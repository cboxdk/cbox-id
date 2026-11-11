import { describe, expect, it } from 'vitest';
import type { ShellCreateFieldSpec } from '@/types';
import { initialValues, payload } from './ShellCreateForm';

const fields: ShellCreateFieldSpec[] = [
    { name: 'email', label: 'Email address', type: 'email', required: true },
    { name: 'sendLink', label: 'Email them a sign-in link', type: 'checkbox', default: true },
    {
        name: 'roles',
        label: 'Role',
        type: 'select',
        array: true,
        default: 'developer',
        options: [
            { value: 'viewer', label: 'Reader' },
            { value: 'developer', label: 'Editor' },
        ],
    },
];

describe('shell create form', () => {
    it("starts from each field's default", () => {
        expect(initialValues(fields)).toEqual({ email: '', sendLink: true, roles: 'developer' });
    });

    it('posts a list field as a one-item list, as the page form does', () => {
        expect(payload(fields, { email: 'a@b.test', sendLink: false, roles: 'viewer' })).toEqual({
            email: 'a@b.test',
            sendLink: false,
            roles: ['viewer'],
        });
    });
});
