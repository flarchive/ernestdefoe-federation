import Extend from 'flarum/common/extenders';
import app from 'flarum/admin/app';

declare const m: any;

const KEY = 'ernestdefoe-federation';
const t = (k: string) => app.translator.trans(`${KEY}.admin.settings.${k}`);

/** Build the live @user@host handle from the current settings + this host. */
function currentHandle(): string {
  const raw =
    (app.data.settings as Record<string, string>)[`${KEY}.username`] ||
    (app.forum.attribute<string>('title') ?? 'community');
  const slug = raw
    .toString()
    .toLowerCase()
    .replace(/[^a-z0-9_]+/g, '_')
    .replace(/^_+|_+$/g, '');
  return `@${slug || 'community'}@${window.location.host}`;
}

export const extend = [
  new Extend.Admin()
    .setting(() => ({
      setting: `${KEY}.enabled`,
      type: 'boolean',
      label: t('enabled_label'),
      help: t('enabled_help'),
    }))
    .setting(() => ({
      setting: `${KEY}.username`,
      type: 'text',
      label: t('username_label'),
      help: t('username_help'),
      placeholder: 'community',
    }))
    .customSetting(
      () =>
        m('.Form-group', [
          m('label', t('handle_label')),
          m('.helpText', t('handle_help')),
          m('pre', m('code', currentHandle())),
        ]),
      30
    ),
];
