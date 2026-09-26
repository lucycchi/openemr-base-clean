import { describe, expect, it } from 'vitest';
import { selectLoadState } from '../../../web/src/hooks/loadState';

describe('selectLoadState', () => {
    it('narrows ready data and passes loading and error through', () => {
        expect(selectLoadState({ status: 'ready', data: { a: 1, b: 2 } }, (d) => d.b)).toEqual({
            status: 'ready',
            data: 2,
        });
        expect(selectLoadState({ status: 'loading' }, () => 0)).toEqual({ status: 'loading' });
        expect(selectLoadState({ status: 'error', error: { kind: 'network' } }, () => 0)).toEqual({
            status: 'error',
            error: { kind: 'network' },
        });
    });
});
