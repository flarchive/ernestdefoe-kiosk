/**
 * Starting points for the "What do you want to sell?" step. Each fills in a
 * new product; the admin picks the item and can change everything.
 */
export interface Template {
  key: string;
  icon: string;
  /** The unlock type it is for; offered only when the forum has that type. */
  type: string;
  productIcon: string;
  prices: { kind: string; amount: string; interval?: string; termDays?: number }[];
}

export const TEMPLATES: Template[] = [
  {
    key: 'membership',
    icon: 'fas fa-crown',
    type: 'group',
    productIcon: 'fas fa-crown',
    prices: [
      { kind: 'recurring', amount: '5', interval: 'month' },
      { kind: 'recurring', amount: '50', interval: 'year' },
    ],
  },
  {
    key: 'premium_section',
    icon: 'fas fa-door-open',
    type: 'tag',
    productIcon: 'fas fa-door-open',
    prices: [{ kind: 'recurring', amount: '3', interval: 'month' }],
  },
  { key: 'supporter', icon: 'fas fa-heart', type: 'group', productIcon: 'fas fa-heart', prices: [{ kind: 'one_off', amount: '20' }] },
  { key: 'pass', icon: 'fas fa-ticket', type: 'tag', productIcon: 'fas fa-ticket', prices: [{ kind: 'fixed_term', amount: '5', termDays: 30 }] },
  {
    key: 'course',
    icon: 'fas fa-chalkboard-user',
    type: 'syllabus-course',
    productIcon: 'fas fa-chalkboard-user',
    prices: [{ kind: 'one_off', amount: '29' }],
  },
  { key: 'album', icon: 'fas fa-images', type: 'atrium-album', productIcon: 'fas fa-images', prices: [{ kind: 'one_off', amount: '9' }] },
  {
    key: 'social_group',
    icon: 'fas fa-people-group',
    type: 'social-group',
    productIcon: 'fas fa-people-group',
    prices: [{ kind: 'recurring', amount: '4', interval: 'month' }],
  },
];
