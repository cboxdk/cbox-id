import { router } from '@inertiajs/react';
import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { setPageProps } from '@/test/page';
import type { LiveMessage } from './realtime';
import { parseMessage } from './realtime';
import { isEditing, useLiveReload } from './useLiveReload';

const listeners = new Map<string, (message: LiveMessage) => void>();

vi.mock('./realtime', async (importActual) => ({
    ...(await importActual<typeof import('./realtime')>()),
    listen: (_config: unknown, channel: string, listener: (message: LiveMessage) => void) => {
        listeners.set(channel, listener);

        return () => listeners.delete(channel);
    },
}));

const signal = (topics: string[]): LiveMessage => ({ topics, changes: [], truncated: false });

function deliver(channel: string, message: LiveMessage): void {
    act(() => listeners.get(channel)?.(message));
}

describe('useLiveReload', () => {
    let reload: ReturnType<typeof vi.spyOn>;

    beforeEach(() => {
        vi.useFakeTimers();
        listeners.clear();
        reload = vi.spyOn(router, 'reload').mockImplementation((options) => {
            options?.onFinish?.({} as never);
        });
        setPageProps({
            realtime: {
                key: 'key',
                host: null,
                port: 443,
                scheme: 'https',
                authUrl: '/realtime/auth',
                channels: ['id.subject.a', 'id.environment.b'],
            },
        });
    });

    afterEach(() => {
        vi.useRealTimers();
        reload.mockRestore();
        document.body.innerHTML = '';
    });

    it('listens on every channel the page was offered and reloads once for a burst', () => {
        renderHook(() => useLiveReload(['devices']));

        expect([...listeners.keys()]).toEqual(['id.subject.a', 'id.environment.b']);

        for (let i = 0; i < 25; i++) {
            deliver('id.subject.a', signal(['devices']));
            deliver('id.environment.b', signal(['devices']));
        }

        expect(reload).not.toHaveBeenCalled();
        act(() => vi.advanceTimersByTime(500));
        expect(reload).toHaveBeenCalledTimes(1);
        expect(reload.mock.calls[0]?.[0]).toMatchObject({ async: true, showProgress: false });
    });

    it('ignores topics the page does not show', () => {
        renderHook(() => useLiveReload(['devices']));

        deliver('id.subject.a', signal(['members', 'audit']));
        act(() => vi.advanceTimersByTime(5000));

        expect(reload).not.toHaveBeenCalled();
    });

    it('reloads only the named props when asked to', () => {
        renderHook(() => useLiveReload(['devices'], { only: ['devices'] }));

        deliver('id.subject.a', signal(['devices']));
        act(() => vi.advanceTimersByTime(500));

        expect(reload.mock.calls[0]?.[0]).toMatchObject({ only: ['devices'] });
    });

    it('keeps reloads at least a second apart however busy the channel', () => {
        renderHook(() => useLiveReload(['devices']));

        deliver('id.subject.a', signal(['devices']));
        act(() => vi.advanceTimersByTime(500));
        deliver('id.subject.a', signal(['devices']));
        act(() => vi.advanceTimersByTime(500));
        expect(reload).toHaveBeenCalledTimes(1);

        act(() => vi.advanceTimersByTime(500));
        expect(reload).toHaveBeenCalledTimes(2);
    });

    it('never reloads under somebody typing: it offers a refresh instead', () => {
        const input = document.createElement('input');
        document.body.appendChild(input);
        input.focus();

        const { result } = renderHook(() => useLiveReload(['members']));

        deliver('id.environment.b', signal(['members']));
        act(() => vi.advanceTimersByTime(500));

        expect(reload).not.toHaveBeenCalled();
        expect(result.current.stale).toBe(true);

        act(() => result.current.refresh());
        expect(reload).toHaveBeenCalledTimes(1);
        expect(result.current.stale).toBe(false);
    });

    it('does not connect at all without live updates', () => {
        setPageProps({ realtime: null });
        renderHook(() => useLiveReload(['devices']));

        expect(listeners.size).toBe(0);
    });
});

describe('isEditing', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('sees a focused field and an open dialog, and nothing else', () => {
        expect(isEditing()).toBe(false);

        const textarea = document.createElement('textarea');
        document.body.appendChild(textarea);
        textarea.focus();
        expect(isEditing()).toBe(true);
        textarea.blur();

        const dialog = document.createElement('div');
        dialog.setAttribute('role', 'dialog');
        document.body.appendChild(dialog);
        expect(isEditing()).toBe(true);
    });
});

describe('parseMessage', () => {
    it('reads a signal and drops anything malformed', () => {
        expect(
            parseMessage({
                topics: ['devices', 7],
                changes: [
                    { topic: 'devices', type: 'device', id: '01H', action: 'created' },
                    { nope: true },
                ],
                truncated: false,
            }),
        ).toEqual({
            topics: ['devices'],
            changes: [{ topic: 'devices', type: 'device', id: '01H', action: 'created' }],
            truncated: false,
        });

        expect(parseMessage(null)).toBeNull();
        expect(parseMessage({ topics: [] })).toBeNull();
        expect(parseMessage('changed')).toBeNull();
    });
});
