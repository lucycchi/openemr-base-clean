import type { LoadError } from '../api/client';

/** Every card's data state. A card renders "error" as a load failure, never as an empty list. */
export type LoadState<T> = { status: 'loading' } | { status: 'error'; error: LoadError } | { status: 'ready'; data: T };
