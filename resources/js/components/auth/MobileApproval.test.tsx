import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, expect, it, vi } from 'vitest';
import { MobileApproval } from './MobileApproval';

afterEach(() => {
    vi.restoreAllMocks();
    vi.useRealTimers();
});
const reply = (body: object) => new Response(JSON.stringify(body), { status: 200 });

it('does not send a push before an explicit click and completes only after server confirmation', async () => {
    const confirmed = vi.fn<(redirect: string) => void>();
    const fetcher = vi
        .spyOn(window, 'fetch')
        .mockResolvedValueOnce(reply({ available: true, status: 'idle' }))
        .mockResolvedValueOnce(reply({ available: true, status: 'pending', code: '123456' }))
        .mockResolvedValueOnce(reply({ available: true, status: 'approved' }))
        .mockResolvedValueOnce(reply({ status: 'confirmed', redirect: '/account' }));
    render(<MobileApproval endpoint="/sudo/mobile" onConfirmed={confirmed} />);
    const button = await screen.findByRole('button', { name: 'Approve on phone' });
    expect(fetcher).toHaveBeenCalledTimes(1);
    expect(fetcher.mock.calls[0]?.[1]?.method).toBe('GET');
    await userEvent.click(button);
    expect(await screen.findByText('123456')).toBeInTheDocument();
    expect(confirmed).not.toHaveBeenCalled();
    await waitFor(() => expect(confirmed).toHaveBeenCalledWith('/account'), { timeout: 6500 });
    expect(confirmed).toHaveBeenCalledTimes(1);
    expect(fetcher.mock.calls[3]?.[1]?.body).toBe(JSON.stringify({ operation: 'complete' }));
}, 10000);

it('keeps an unavailable phone out of the way of other methods', async () => {
    vi.spyOn(window, 'fetch').mockResolvedValue(reply({ available: false, status: 'unavailable' }));
    const { container } = render(
        <MobileApproval endpoint="/mfa/mobile" onConfirmed={vi.fn<(redirect: string) => void>()} />,
    );
    await act(async () => {});
    expect(container).toBeEmptyDOMElement();
});

it('shows denial and offers a new request without completing login', async () => {
    const confirmed = vi.fn<(redirect: string) => void>();
    vi.spyOn(window, 'fetch').mockResolvedValue(reply({ available: true, status: 'denied' }));
    render(<MobileApproval endpoint="/mfa/mobile" onConfirmed={confirmed} />);
    expect(await screen.findByRole('alert')).toHaveTextContent('Denied');
    expect(screen.getByRole('button', { name: 'Approve on phone' })).toBeEnabled();
    expect(confirmed).not.toHaveBeenCalled();
});

it('cancels the pending browser binding and never completes after unmount', async () => {
    const confirmed = vi.fn<(redirect: string) => void>();
    const fetcher = vi
        .spyOn(window, 'fetch')
        .mockResolvedValueOnce(reply({ available: true, status: 'pending', code: '654321' }))
        .mockResolvedValueOnce(reply({ status: 'cancelled' }));
    const { unmount } = render(<MobileApproval endpoint="/mfa/mobile" onConfirmed={confirmed} />);
    await userEvent.click(await screen.findByRole('button', { name: 'Cancel' }));
    expect(fetcher.mock.calls[1]?.[1]?.body).toBe(
        JSON.stringify({ operation: 'cancel', code: '654321' }),
    );
    unmount();
    expect(confirmed).not.toHaveBeenCalled();
});

it('offers the universal link to open the app while the request waits, and keeps polling', async () => {
    const link = 'https://login.cboxid.com/authenticator/approve/01JD2Z7Q8M3K4N5P6R7S8T9V0W';
    vi.spyOn(window, 'fetch').mockResolvedValue(
        reply({ available: true, status: 'pending', code: '246810', appLink: link }),
    );
    render(
        <MobileApproval endpoint="/mfa/mobile" onConfirmed={vi.fn<(redirect: string) => void>()} />,
    );
    const open = await screen.findByRole('link', { name: 'Open Cbox ID app' });
    expect(open).toHaveAttribute('href', link);
    // Same tab, so a missing app lands on the help page and Back returns to this one.
    expect(open).not.toHaveAttribute('target');
    expect(screen.getByText('246810')).toBeInTheDocument();
});

it('checks at once when the person comes back from the app', async () => {
    const confirmed = vi.fn<(redirect: string) => void>();
    const fetcher = vi
        .spyOn(window, 'fetch')
        .mockResolvedValueOnce(
            reply({
                available: true,
                status: 'pending',
                code: '135790',
                appLink: 'https://x.test/authenticator/approve/01JD2Z7Q8M3K4N5P6R7S8T9V0W',
            }),
        )
        .mockResolvedValueOnce(reply({ available: true, status: 'approved', code: '135790' }))
        .mockResolvedValueOnce(reply({ status: 'confirmed', redirect: '/dashboard' }));
    render(<MobileApproval endpoint="/mfa/mobile" onConfirmed={confirmed} />);
    await screen.findByText('135790');
    act(() => {
        document.dispatchEvent(new Event('visibilitychange'));
    });
    await waitFor(() => expect(confirmed).toHaveBeenCalledWith('/dashboard'), { timeout: 1500 });
    expect(fetcher).toHaveBeenCalledTimes(3);
});

it('shows no app button on a desktop', async () => {
    vi.spyOn(window, 'fetch').mockResolvedValue(
        reply({ available: true, status: 'pending', code: '112233', appLink: null }),
    );
    render(
        <MobileApproval endpoint="/mfa/mobile" onConfirmed={vi.fn<(redirect: string) => void>()} />,
    );
    await screen.findByText('112233');
    expect(screen.queryByRole('link', { name: 'Open Cbox ID app' })).toBeNull();
});
