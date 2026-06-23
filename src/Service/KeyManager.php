<?php

namespace ErnestDefoe\Federation\Service;

use Flarum\User\User;

/**
 * Owns RSA keypairs for the community actor and each member actor.
 *
 * Private keys are encrypted at rest (AES-256-GCM) with a secret held OUTSIDE
 * the database — an explicit FEDERATION_SECRET env var when set, otherwise a key
 * derived from the on-disk Flarum config. A reader with only database access can
 * therefore no longer lift a usable signing key. Stored values are tagged with
 * an "enc:v1:" prefix; legacy plaintext keys are read transparently and
 * re-encrypted the next time they are persisted.
 */
class KeyManager
{
    private const ENC_PREFIX = 'enc:v1:';

    private const CIPHER = 'aes-256-gcm';

    /** @var string|null cached 32-byte at-rest secret */
    private ?string $secret = null;

    public function __construct(
        protected Settings $settings,
    ) {}

    /** @return array{0:string,1:string} [publicPem, privatePem] */
    public function generateKeypair(): array
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($res === false) {
            throw new \RuntimeException('Could not generate a federation keypair (OpenSSL).');
        }
        openssl_pkey_export($res, $priv);
        $pub = (string) openssl_pkey_get_details($res)['key'];

        return [$pub, $priv];
    }

    // ---- Community keypair (stored in settings) ----------------------------

    /** @return array{public:string,private:string} PEM strings */
    public function communityKeys(): array
    {
        $pub = (string) $this->settings->get('public_key', '');
        $priv = $this->decrypt((string) $this->settings->get('private_key', ''));

        if ($pub !== '' && $priv !== '') {
            // Opportunistically migrate a legacy plaintext key to ciphertext.
            $this->ensureEncryptedSetting('private_key');

            return ['public' => $pub, 'private' => $priv];
        }

        [$pub, $priv] = $this->generateKeypair();
        $this->settings->set('public_key', $pub);
        $this->settings->set('private_key', $this->encrypt($priv));

        return ['public' => $pub, 'private' => $priv];
    }

    public function communityPublicKeyPem(): string
    {
        return $this->communityKeys()['public'];
    }

    // ---- Per-member keypair (stored on the user row) -----------------------

    /** @return array{public:string,private:string} a member's own keypair (lazy) */
    public function userKeys(User $user): array
    {
        if ($user->ap_public_key && $user->ap_private_key) {
            $priv = $this->decrypt((string) $user->ap_private_key);
            if (! $this->isEncrypted((string) $user->ap_private_key)) {
                // Migrate a legacy plaintext member key to ciphertext at rest.
                $user->forceFill(['ap_private_key' => $this->encrypt($priv)])->save();
            }

            return ['public' => $user->ap_public_key, 'private' => $priv];
        }

        [$pub, $priv] = $this->generateKeypair();
        $user->forceFill([
            'ap_public_key' => $pub,
            'ap_private_key' => $this->encrypt($priv),
        ])->save();

        return ['public' => $pub, 'private' => $priv];
    }

    // ---- Encryption at rest ------------------------------------------------

    private function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::ENC_PREFIX);
    }

    private function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, $this->secret(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            // Never silently fall back to plaintext — fail loudly instead.
            throw new \RuntimeException('Could not encrypt a federation private key.');
        }

        return self::ENC_PREFIX.base64_encode($iv.$tag.$cipher);
    }

    /** Decrypt a stored value; returns legacy plaintext unchanged. */
    private function decrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (! $this->isEncrypted($value)) {
            return $value; // legacy plaintext
        }
        $raw = base64_decode(substr($value, strlen(self::ENC_PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) {
            return '';
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, self::CIPHER, $this->secret(), OPENSSL_RAW_DATA, $iv, $tag);

        return $plain === false ? '' : $plain;
    }

    /** Re-store a community setting as ciphertext if it is still plaintext. */
    private function ensureEncryptedSetting(string $key): void
    {
        $current = (string) $this->settings->get($key, '');
        if ($current !== '' && ! $this->isEncrypted($current)) {
            $this->settings->set($key, $this->encrypt($current));
        }
    }

    /**
     * A 32-byte at-rest secret that lives OUTSIDE the database:
     *  1. the FEDERATION_SECRET environment variable, when set; otherwise
     *  2. a key derived from the on-disk config.php (its database section), which
     *     an attacker with only DB read access cannot reach.
     */
    private function secret(): string
    {
        if ($this->secret !== null) {
            return $this->secret;
        }

        $env = getenv('FEDERATION_SECRET');
        if (is_string($env) && $env !== '') {
            return $this->secret = hash('sha256', 'ernestdefoe-federation|'.$env, true);
        }

        $config = $this->settings->config();
        $material = '';
        try {
            $material = json_encode($config['database'] ?? null) ?: '';
        } catch (\Throwable $e) {
            $material = '';
        }
        if ($material === '' || $material === 'null') {
            $material = (string) $config->url();
        }

        return $this->secret = hash('sha256', 'ernestdefoe-federation|'.$material, true);
    }
}
