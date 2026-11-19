import Extend from 'flarum/common/extenders';
import Purchase from './models/Purchase';

export default [new Extend.Store().add('kiosk-purchases', Purchase)];
