<?php

declare(strict_types=1);

use App\Http\Middleware\AssignRequestId;

/*
|--------------------------------------------------------------------------
| Every response names its request, so a caller can quote it and an operator can find
| every line it wrote.
|--------------------------------------------------------------------------
*/

it('names every response, and carries the id in the management API\'s error envelope', function (): void {
    $response = $this->getJson('/api/v1/apis')->assertUnauthorized();
    $id = $response->headers->get(AssignRequestId::HEADER);

    expect($id)->toMatch('/^[0-9a-z]{26}$/')
        ->and($response->json('request_id'))->toBe($id);
});

it('keeps a well-formed inbound id and replaces one it should not echo', function (): void {
    $kept = $this->withHeader(AssignRequestId::HEADER, 'trace-1234abcd')->getJson('/api/v1/apis');

    expect($kept->headers->get(AssignRequestId::HEADER))->toBe('trace-1234abcd');

    $this->flushHeaders();

    $replaced = $this->withHeader(AssignRequestId::HEADER, "evil\r\nSet-Cookie: x=1")->getJson('/api/v1/apis');

    expect($replaced->headers->get(AssignRequestId::HEADER))->toMatch('/^[0-9a-z]{26}$/');
});
