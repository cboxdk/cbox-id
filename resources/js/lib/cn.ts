import { type ClassValue, clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

/**
 * Join class names, with later Tailwind utilities beating earlier ones.
 *
 * Every primitive in `ui/` takes a `className` so a caller can adjust one thing without
 * forking the component. Without the merge, `<Button className="px-6">` would emit both
 * `px-4` and `px-6` and the winner would be decided by stylesheet order — which is a
 * coin toss the caller cannot see.
 */
export function cn(...inputs: ClassValue[]): string {
    return twMerge(clsx(inputs));
}
