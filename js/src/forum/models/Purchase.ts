import Model from 'flarum/common/Model';

/**
 * A purchase as a notification's subject (PurchaseResource): its order number
 * and product name, nothing more. 🚨 It must be in the forum's store, or a
 * member's notifications list stops loading at the first Kiosk notification.
 */
export default class Purchase extends Model {
  number = Model.attribute<string>('number');
  productName = Model.attribute<string>('productName');
}
