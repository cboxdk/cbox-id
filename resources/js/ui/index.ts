/**
 * THE DESIGN SYSTEM, as one import.
 *
 * `import { Button, Field, Panel } from '@/ui'` — one path for every page, so a primitive
 * can move file without touching a hundred call sites, and so "what does this console
 * have?" is answered by reading one file.
 *
 * A page should not need anything outside this list. If it does, the thing it needs is
 * probably a primitive that has not been written yet — write it here rather than
 * assembling it inline, or the console grows two spellings of the same control.
 */
export { Avatar } from './Avatar';
export { Badge, type BadgeTone } from './Badge';
export { Button, type ButtonProps, type ButtonSize, type ButtonVariant } from './Button';
export { Checkbox } from './Checkbox';
export { Combobox, type ComboboxOption } from './Combobox';
export { ConfirmDelete } from './ConfirmDelete';
export { CopyButton } from './CopyButton';
export { Dialog, DialogClose } from './Dialog';
export { Divider } from './Divider';
export {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from './DropdownMenu';
export { EmptyState } from './EmptyState';
export { Field, useFieldControl } from './Field';
export { Help } from './Help';
export { Icon, type IconProps } from './Icon';
export { type IconName, iconNames, iconPaths } from './icons';
export { Input, type InputProps, Textarea } from './Input';
export { Kv, KvList } from './Kv';
export { PageHeader } from './PageHeader';
export { Pagination } from './Pagination';
export { Panel, type PanelProps } from './Panel';
export { Pill, type PillTone } from './Pill';
export { Popover, PopoverAnchor, PopoverContent, PopoverTrigger } from './Popover';
export { RadioGroup, type RadioOption } from './RadioGroup';
export { Select, type SelectOption } from './Select';
export { Spinner } from './Spinner';
export { Stat, type StatTone } from './Stat';
export { Switch } from './Switch';
export { Tab, Tabs } from './Tabs';
export { Table, Td, TdMono, Th } from './Table';
export { Tooltip, TooltipProvider } from './Tooltip';
