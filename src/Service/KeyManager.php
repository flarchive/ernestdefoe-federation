<?php

namespace ErnestDefoe\Federation\Service;

use ErnestDefoe\Federation\FederationUserData;
use Flarum\User\User;
use Illuminate\Database\ConnectionResolverInterface;

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
        protected ConnectionResolverInterface $db,
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
        // Fast path: already generated (cached read, no lock needed).
        $pub = (string) $this->settings->get('public_key', '');
        $priv = $this->decrypt((string) $this->settings->get('private_key', ''));

        if ($pub !== '' && $priv !== '') {
            // Opportunistically migrate a legacy plaintext key to ciphertext
            // (idempotent + race-safe — re-encrypting yields the same plaintext).
            $this->ensureEncryptedSetting('private_key');

            return ['public' => $pub, 'private' => $priv];
        }

        // First-use generation: serialise under a settings-row lock so two
        // concurrent /federation/actor hits can't generate competing keypairs —
        // a mismatched public/private key would break the actor permanently.
        return $this->db->connection()->transaction(function () {
            $conn = $this->db->connection();
            $privKey = Settings::PREFIX.'private_key';
            $pubKey = Settings::PREFIX.'public_key';

            $conn->table('settings')->insertOrIgnore(['key' => $privKey, 'value' => '']);
            $conn->table('settings')->where('key', $privKey)->lockForUpdate()->first();

            // Re-read straight from the (locked) DB row, not the cached repo.
            $pub = (string) ($conn->table('settings')->where('key', $pubKey)->value('value') ?? '');
            $priv = $this->decrypt((string) ($conn->table('settings')->where('key', $privKey)->value('value') ?? ''));
            if ($pub !== '' && $priv !== '') {
                return ['public' => $pub, 'private' => $priv];
            }

            [$pub, $priv] = $this->generateKeypair();
            $this->settings->set('public_key', $pub);
            $this->settings->set('private_key', $this->encrypt($priv));

            return ['public' => $pub, 'private' => $priv];
        });
    }

    public function communityPublicKeyPem(): string
    {
        return $this->communityKeys()['public'];
    }

    // ---- Per-member keypair (stored on the user row) -----------------------

    /** @return array{public:string,private:string} a member's own keypair (lazy) */
    public function userKeys(User $user): array
    {
        // Serialise first-time generation: two concurrent actor fetches must not
        // each generate a keypair and clobber each other — that would leave a
        // public key not matching the already-distributed private key, breaking
        // the actor permanently. Ensure the row exists, then lock it.
        return $this->db->connection()->transaction(function () use ($user) {
            FederationUserData::query()->insertOrIgnore(['user_id' => $user->id]);
            $data = FederationUserData::whereKey($user->id)->lockForUpdate()->first();
            $user->setRelation('federationData', $data);

            if ($data->ap_public_key && $data->ap_private_key) {
                $priv = $this->decrypt((string) $data->ap_private_key);
                if (! $this->isEncrypted((string) $data->ap_private_key)) {
                    // Migrate a legacy plaintext member key to ciphertext at rest.
                    $data->ap_private_key = $this->encrypt($priv);
                    $data->save();
                }

                return ['public' => $data->ap_public_key, 'private' => $priv];
            }

            [$pub, $priv] = $this->generateKeypair();
            $data->ap_public_key = $pub;
            $data->ap_private_key = $this->encrypt($priv);
            $data->save();

            return ['public' => $pub, 'private' => $priv];
        });
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
