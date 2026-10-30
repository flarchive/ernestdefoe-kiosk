import app from 'flarum/common/app';

export const t = (key: string, params: Record<string, unknown> = {}) => app.translator.trans(`ernestdefoe-kiosk.${key}`, params);
