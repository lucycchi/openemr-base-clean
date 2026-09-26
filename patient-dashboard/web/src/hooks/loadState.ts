import type { LoadError } from '../api/client';

/** Every card's data state. A card renders "error" as a load failure, never as an empty list. */
export type LoadState<T> = { status: 'loading' } | { status: 'error'; error: LoadError } | { status: 'ready'; data: T };

/** Narrows a ready state to part of its data; loading and error states pass through unchanged. */
export function selectLoadState<T, U>(state: LoadState<T>, select: (data: T) => U): LoadState<U> {
    return state.status === 'ready' ? { status: 'ready', data: select(state.data) } : state;
}
