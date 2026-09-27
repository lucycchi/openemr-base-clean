/**
 * The three states every card's data can be in, shared by all the card hooks in this folder and by the
 * card components that draw them. A hook hands one of these to its card; the card shows "Loading…" for
 * "loading", "Couldn't load" for "error", and the data for "ready".
 */
import type { LoadError } from '../api/client';

/**
 * Every card's data state. A card renders "error" as a load failure, never as an empty list.
 * The `|` bars mean "one of these shapes": still loading; failed, with the reason; or ready, with the
 * data. `T` stands for whatever that card's data is (a list of allergies, the header, and so on).
 */
export type LoadState<T> = { status: 'loading' } | { status: 'error'; error: LoadError } | { status: 'ready'; data: T };
