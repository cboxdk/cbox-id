<?php

declare(strict_types=1);

namespace App\Platform\ServiceAccess;

use Cbox\Billing\Client\Http\WireId;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/** No local waitlist mirror: Billing owns registrations and their FIFO position. */
final readonly class BillingWaitlists
{
    public function __construct(private ServiceAccessSettings $settings) {}

    /** @return array{services: list<Service>, page: int, lastPage: int} */
    public function page(int $page, ?string $service = null, string $kind = 'service'): array
    {
        $response = $this->request('GET', '/api/v1/access/directory', ['page' => $page, 'kind' => $kind] + ($service === null ? [] : ['service' => $service]));
        $data = $response->json();
        if (! is_array($data) || ! is_array($data['data'] ?? null) || ! is_int($data['current_page'] ?? null) || ! is_int($data['last_page'] ?? null)) {
            throw new ServiceAccessUnavailable('Malformed service directory pagination.');
        }
        $services = [];
        foreach ($data['data'] as $row) {
            $program = Service::from($row);
            if ($program->kind !== $kind) {
                throw new ServiceAccessUnavailable('The directory returned a different programme kind.');
            }
            $services[] = $program;
        }

        return ['services' => $services, 'page' => $data['current_page'], 'lastPage' => $data['last_page']];
    }

    /**
     * @param  list<string>  $programs  program ids as Billing names them (`acp_…`)
     * @return array<string, string|null>
     */
    public function registrations(array $programs, string $subject, string $email, ?string $organization = null): array
    {
        if ($programs === []) {
            return [];
        }
        $data = $this->request('POST', '/api/v1/access/directory/registrations', ['program_ids' => $programs, 'subject' => $subject, 'email' => $email, 'organization' => $organization])->json('data');
        if (! is_array($data)) {
            throw new ServiceAccessUnavailable('Malformed registration response.');
        }
        $registrations = [];
        foreach ($data as $row) {
            if (! is_array($row)) {
                throw new ServiceAccessUnavailable('Unexpected registration response.');
            }
            $program = WireId::from($row['program_id'] ?? null);
            if ($program === null || ! in_array($program, $programs, true)) {
                throw new ServiceAccessUnavailable('Unexpected registration response.');
            }
            $registration = $row['registration'] ?? null;
            $status = is_array($registration) ? ($registration['status'] ?? null) : null;
            if ($registration !== null && ! in_array($status, ['waiting', 'invited', 'accepted', 'following', 'revoked'], true)) {
                throw new ServiceAccessUnavailable('Unknown registration status.');
            }
            $registrations[$program] = is_string($status) ? $status : null;
        }
        if (count($registrations) !== count($programs)) {
            throw new ServiceAccessUnavailable('Incomplete registration response.');
        }

        return $registrations;
    }

    /** @param array<string, string|list<string>> $answers */
    public function join(string $program, string $subject, string $email, ?string $country, array $answers, string $reference, ?string $organization = null, string $interest = 'test'): void
    {
        $response = $this->request('POST', '/api/v1/access/directory/'.rawurlencode($program).'/join', [
            'subject' => $subject, 'email' => $email, 'country' => $country, 'organization' => $organization, 'interest' => $interest,
            'answers' => $answers === [] ? (object) [] : $answers, 'reference' => $reference,
        ], $reference);
        if (! in_array($response->json('registration.status'), ['waiting', 'invited', 'accepted', 'following', 'revoked'], true)) {
            throw new ServiceAccessUnavailable('The registration was not acknowledged.');
        }
    }

    public function leave(string $program, string $subject, string $organization, string $reference): void
    {
        $response = $this->request('POST', '/api/v1/access/directory/'.rawurlencode($program).'/leave', [
            'subject' => $subject, 'organization' => $organization, 'reference' => $reference,
        ], $reference);
        if ($response->json('status') !== 'withdrawn') {
            throw new ServiceAccessUnavailable('The withdrawal was not acknowledged.');
        }
    }

    /**
     * The policy as Billing sent it, checked, so it can be kept as plain data.
     *
     * @return array<array-key, mixed>
     */
    public function productPolicyData(): array
    {
        $program = $this->request('GET', '/api/v1/access/services/cbox-id/policy', [])->json('program');
        RegistrationPolicy::from($program);

        return is_array($program) ? $program : throw new ServiceAccessUnavailable('Malformed registration policy.');
    }

    public function productAllowed(string $subject, string $organization): bool
    {
        $allowed = $this->request('POST', '/api/v1/access/services/cbox-id/check', ['subject' => $subject, 'organization' => $organization])->json('allowed');
        if (! is_bool($allowed)) {
            throw new ServiceAccessUnavailable('Malformed admission decision.');
        }

        return $allowed;
    }

    public function admitProduct(string $subject, string $email, string $organization, string $name, ?string $code, string $reference): void
    {
        $response = $this->request('POST', '/api/v1/access/services/cbox-id/admit', [
            'subject' => $subject, 'email' => $email, 'organization' => $organization, 'organization_name' => $name, 'code' => $code, 'reference' => $reference,
        ], $reference);
        if ($response->json('allowed') !== true) {
            throw new ServiceAccessUnavailable('Admission was not acknowledged.');
        }
    }

    /** @param array<string, string|list<string>> $answers */
    public function joinProductGuest(string $email, ?string $country, array $answers, string $reference): void
    {
        $response = $this->request('POST', '/api/v1/access/services/cbox-id/join-guest', ['email' => $email, 'country' => $country, 'answers' => $answers, 'reference' => $reference], $reference);
        if ($response->json('status') !== 'confirmation_required') {
            throw new ServiceAccessUnavailable('Enrollment was not acknowledged.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function request(string $method, string $path, array $payload, ?string $reference = null): Response
    {
        $connection = $this->settings->connection(str_starts_with($path, '/api/v1/access/services/cbox-id/'));
        try {
            $client = Http::acceptJson()->withToken($connection['token'])->connectTimeout(3)->timeout(8)->withoutRedirecting();
            if ($reference !== null) {
                $client = $client->withHeaders(['Idempotency-Key' => $reference]);
            }
            $response = $client->send($method, $connection['url'].$path, [$method === 'GET' ? 'query' : 'json' => $payload]);
        } catch (ConnectionException) {
            throw new ServiceAccessUnavailable('Service directory could not be reached.');
        }
        if ($response->status() === 422 && $reference !== null) {
            $errors = [];
            $body = $response->json('errors');
            foreach (is_array($body) ? $body : [] as $field => $messages) {
                if (is_string($field) && (in_array($field, ['country', 'answers', 'code', 'email', 'organization'], true) || str_starts_with($field, 'answers.')) && is_array($messages)) {
                    foreach ($messages as $message) {
                        if (is_string($message)) {
                            $errors[$field][] = $message;
                        }
                    }
                }
            }
            throw ValidationException::withMessages($errors === [] ? ['waitlist' => __('service_access.request_failed')] : $errors);
        }
        if (! $response->successful()) {
            throw new ServiceAccessUnavailable('Service directory refused the request ('.$response->status().').');
        }

        return $response;
    }
}
