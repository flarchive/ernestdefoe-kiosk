/**
 * Drag-to-reorder for a list, by its handle, with pointer events so it works
 * with a mouse, a pen or a finger (arrow keys too). The list re-renders as the
 * row moves; the new order is the array itself. From Causeway.
 *
 *   <ul data-sortable> <li {...sortable.row(i)}> <span {...sortable.handle(i)} /> … </li> </ul>
 */
export default class Sortable<T> {
  dragging: number | null = null;

  constructor(
    private getItems: () => T[],
    private onChange: () => void
  ) {}

  row(index: number) {
    return { 'data-sort-index': index, className: this.dragging === index ? 'is-dragging' : '' };
  }

  handle(index: number) {
    return {
      className: 'KioskSortHandle',
      role: 'button',
      tabindex: 0,
      'aria-roledescription': 'sortable',
      onpointerdown: (e: PointerEvent) => this.start(e, index),
      onkeydown: (e: KeyboardEvent) => this.key(e, index),
    };
  }

  key(e: KeyboardEvent, index: number) {
    const items = this.getItems();
    let to: number | null = null;
    if (e.key === 'ArrowUp' && index > 0) to = index - 1;
    if (e.key === 'ArrowDown' && index < items.length - 1) to = index + 1;
    if (to === null) return;
    e.preventDefault();
    this.move(index, to);
    this.onChange();
    // Keep focus on the moved row's handle.
    setTimeout(() => (document.querySelector(`[data-sort-index="${to}"] .KioskSortHandle`) as HTMLElement | null)?.focus(), 0);
  }

  start(e: PointerEvent, index: number) {
    if (e.button !== undefined && e.button !== 0) return;
    e.preventDefault();
    const list = (e.target as HTMLElement).closest('[data-sortable]');
    if (!list) return;

    this.dragging = index;
    const handle = e.currentTarget as HTMLElement;
    handle.setPointerCapture?.(e.pointerId);

    const move = (ev: PointerEvent) => {
      const rows = Array.from(list.querySelectorAll(':scope > [data-sort-index]'));
      let target = this.dragging!;
      rows.forEach((row, i) => {
        const r = row.getBoundingClientRect();
        if (ev.clientY > r.top + r.height / 2) target = i;
      });
      const first = rows[0]?.getBoundingClientRect();
      if (first && ev.clientY < first.top + first.height / 2) target = 0;
      if (target !== this.dragging) {
        this.move(this.dragging!, target);
        this.dragging = target;
        m.redraw();
      }
    };

    const end = () => {
      handle.removeEventListener('pointermove', move);
      handle.removeEventListener('pointerup', end);
      handle.removeEventListener('pointercancel', end);
      this.dragging = null;
      this.onChange();
      m.redraw();
    };

    handle.addEventListener('pointermove', move);
    handle.addEventListener('pointerup', end);
    handle.addEventListener('pointercancel', end);
    m.redraw();
  }

  move(from: number, to: number) {
    const items = this.getItems();
    const [item] = items.splice(from, 1);
    items.splice(to, 0, item);
  }
}
