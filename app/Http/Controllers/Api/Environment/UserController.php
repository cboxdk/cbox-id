<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Environment;

use App\Http\Controllers\Controller;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Environment plane › users. Lists, creates, reads and deactivates the end-user
 * identities inside one environment. Reads query the hard environment-scoped
 * built-in {@see User} store; writes delegate to the {@see Subjects} service so a
 * host that has swapped its own subject resolver stays authoritative. Deactivation
 * is a soft disable (the identity can no longer authenticate), never a hard delete
 * — deleting people is not something an API key should do.
 */
final class UserController extends Controller
{
    use PaginatesEnvironmentResources;

    public function index(Request $request): JsonResponse
    {
        [$limit, $after] = $this->cursor($request);

        $query = User::query()->orderBy('id')->limit($limit + 1);

        if ($after !== null) {
            $query->where('id', '>', $after);
        }

        return $this->page($query->get(), $limit, fn (User $u): array => $this->present($u));
    }

    public function show(string $id): JsonResponse
    {
        $user = User::query()->find($id);

        if ($user === null) {
            return $this->notFound('user');
        }

        return response()->json(['data' => $this->present($user)]);
    }

    public function store(Request $request, Subjects $subjects): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'name' => ['sometimes', 'nullable', 'string', 'max:190'],
            'password' => ['sometimes', 'nullable', 'string', 'min:8', 'max:255'],
        ]);

        $email = $request->string('email')->toString();

        if ($subjects->findByEmail($email) !== null) {
            return response()->json(['error' => 'email_taken', 'message' => 'A user with that email already exists in this environment.'], 422);
        }

        $name = $request->filled('name') ? $request->string('name')->toString() : null;
        $password = $request->filled('password') ? $request->string('password')->toString() : null;

        $subject = $subjects->create($email, $name, $password);

        $user = User::query()->find($subject->id);

        return response()->json([
            'data' => $user !== null ? $this->present($user) : [
                'id' => $subject->id,
                'email' => $subject->email,
                'name' => $subject->name,
            ],
        ], 201);
    }

    public function destroy(string $id, Subjects $subjects): JsonResponse
    {
        $user = User::query()->find($id);

        if ($user === null) {
            return $this->notFound('user');
        }

        $subjects->deactivate($id);

        return response()->json(['data' => $this->present($user->fresh() ?? $user)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'status' => $user->status->value,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
        ];
    }
}
