<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Platform\Api\ApiUrls;
use App\Platform\Api\V1\SpecNode;
use App\Platform\Api\V1\V1OpenApi;
use App\Platform\CspNonce;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * `GET /docs`: the reference, rendered on the server from `openapi.json` — the API host
 * serves no application pages, so this is one self-contained document with no session, no
 * asset pipeline and one small script (the filter), under the response's CSP nonce.
 */
final class ApiDocsController
{
    /** The machine codes a problem may carry that the reference explains. */
    private const array PROBLEMS = [
        'bad_request' => 'The request could not be understood: a malformed body or parameter.',
        'unauthorized' => 'No valid credential. Send `Authorization: Bearer …` with a key or an access token.',
        'invalid_token' => 'The access token is invalid, expired, or audienced to something else. A retired audience says which resource to request instead.',
        'forbidden' => 'The credential is valid but may not do this: a scope it lacks, or a role that does not allow it.',
        'insufficient_scope' => 'The token was not granted the scope this operation needs; the challenge names it.',
        'insufficient_user_authentication' => 'The person must sign in again, more strongly or more recently, and repeat the call.',
        'environment_required' => 'A platform-root token acting inside an environment must name it in `Cbox-Environment`.',
        'not_found' => 'No such resource — or not one this credential can see. Another environment\'s records are never visible.',
        'issuer_moved' => 'The host is a name this deployment was issued under before. `issuer` names the issuer to configure instead, and `api_base` the API.',
        'method_not_allowed' => 'That method is not allowed on this path.',
        'conflict' => 'The request conflicts with the resource\'s current state.',
        'validation_failed' => 'A field is missing or wrong. `errors` names each field and what is wrong with it.',
        'unprocessable_entity' => 'The request was well-formed but could not be carried out.',
        'approval_required' => 'The action waits for a person\'s approval: poll the approval\'s `poll_url`.',
        'rate_limited' => 'Too many requests. Wait for the interval in `Retry-After`.',
        'server_error' => 'Something failed on our side. The response\'s `X-Request-Id` lets support find it.',
        'service_unavailable' => 'The service is temporarily unavailable. Retry with backoff.',
    ];

    public function __invoke(CspNonce $nonce): Response
    {
        $document = @file_get_contents(V1OpenApi::path());
        $spec = is_string($document) ? json_decode($document, true) : null;

        abort_if(! is_array($spec), 404);

        return response($this->page(SpecNode::map($spec), $nonce->value()), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    /** `/problems/{code}` resolves to the code's entry in the reference. */
    public function problem(string $code): RedirectResponse
    {
        return redirect()->away(ApiUrls::docs().'#problem-'.$code, 302);
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function page(array $spec, string $nonce): string
    {
        $operations = [];

        foreach (SpecNode::map($spec['paths'] ?? null) as $path => $item) {
            foreach (SpecNode::map($item) as $method => $operation) {
                if (is_array($operation)) {
                    $tag = SpecNode::string(SpecNode::list($operation['tags'] ?? null)[0] ?? null);
                    $operations[$tag !== '' ? $tag : 'General'][] = [$method, $path, SpecNode::map($operation)];
                }
            }
        }

        $nav = '';
        $body = '';

        foreach (SpecNode::list($spec['x-tagGroups'] ?? null) as $group) {
            $group = SpecNode::map($group);
            $nav .= '<p class="group">'.e(SpecNode::string($group['name'] ?? null)).'</p><ul>';

            foreach (SpecNode::list($group['tags'] ?? null) as $tag) {
                $tag = SpecNode::string($tag);

                if ($tag === '' || ! isset($operations[$tag])) {
                    continue;
                }

                $anchor = 'tag-'.self::slug($tag);
                $label = str_contains($tag, ' › ') ? substr($tag, strpos($tag, ' › ') + strlen(' › ')) : $tag;
                $nav .= '<li data-filter="'.e(mb_strtolower($tag)).'"><a href="#'.$anchor.'">'.e($label).'</a></li>';
                $body .= '<section id="'.$anchor.'" class="tag" data-tag="'.e(mb_strtolower($tag)).'"><h2>'.e($tag).'</h2>';

                usort($operations[$tag], static fn (array $a, array $b): int => [$a[1], $a[0]] <=> [$b[1], $b[0]]);

                foreach ($operations[$tag] as [$method, $path, $operation]) {
                    $body .= $this->operation($method, $path, $operation);
                }

                $body .= '</section>';
            }

            $nav .= '</ul>';
        }

        $problems = '';

        foreach (self::PROBLEMS as $code => $meaning) {
            $problems .= '<dt id="problem-'.e($code).'"><code>'.e($code).'</code></dt><dd>'.self::inline($meaning).'</dd>';
        }

        $info = SpecNode::map($spec['info'] ?? null);
        $title = e(SpecNode::string($info['title'] ?? null) ?: 'Cbox ID API');
        $intro = self::markdown(SpecNode::string($info['description'] ?? null));
        $base = e(ApiUrls::base());
        $openApi = e(ApiUrls::openApi());

        return <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{$title} reference</title>
        <style>
        :root{--bg:#fbfbfa;--panel:#fff;--ink:#16181d;--muted:#5d6370;--line:#e4e5e8;--accent:#3346d3;--code:#f2f3f5;--get:#11795a;--post:#3346d3;--patch:#a35a00;--put:#7a3fb8;--delete:#b42318}
        @media (prefers-color-scheme:dark){:root{--bg:#111318;--panel:#171a21;--ink:#e8e9ec;--muted:#9aa1ad;--line:#272b34;--accent:#8c9bff;--code:#1f232c;--get:#4cc79a;--post:#8c9bff;--patch:#f0a64b;--put:#c39af0;--delete:#ff7a6e}}
        *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif}
        a{color:var(--accent)}code{font:13px/1.4 ui-monospace,SFMono-Regular,Menlo,monospace;background:var(--code);padding:1px 5px;border-radius:4px}
        header{position:sticky;top:0;z-index:2;background:var(--panel);border-bottom:1px solid var(--line);padding:12px 16px;display:flex;gap:12px;align-items:center;flex-wrap:wrap}
        header h1{font-size:16px;margin:0 12px 0 0}header .base{color:var(--muted)}header input{margin-left:auto;min-width:220px;flex:1;max-width:360px;padding:7px 10px;border:1px solid var(--line);border-radius:8px;background:var(--bg);color:var(--ink);font:inherit}
        .wrap{display:grid;grid-template-columns:260px minmax(0,1fr);max-width:1280px;margin:0 auto}
        nav{position:sticky;top:57px;align-self:start;max-height:calc(100vh - 57px);overflow:auto;padding:16px;border-right:1px solid var(--line)}
        nav .group{font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin:16px 0 6px}nav ul{list-style:none;margin:0;padding:0}nav li a{display:block;padding:3px 6px;border-radius:6px;color:var(--ink);text-decoration:none}nav li a:hover{background:var(--code)}
        main{padding:16px 24px 64px;min-width:0}main>section.intro{max-width:780px}
        .tag h2{font-size:20px;margin:40px 0 12px;padding-top:8px;border-top:1px solid var(--line)}
        details.op{background:var(--panel);border:1px solid var(--line);border-radius:10px;margin:8px 0}details.op>summary{cursor:pointer;list-style:none;display:flex;gap:10px;align-items:baseline;padding:10px 12px;flex-wrap:wrap}
        details.op>summary::-webkit-details-marker{display:none}.m{font:600 12px ui-monospace,monospace;text-transform:uppercase;min-width:58px}.m.get{color:var(--get)}.m.post{color:var(--post)}.m.patch{color:var(--patch)}.m.put{color:var(--put)}.m.delete{color:var(--delete)}
        .p{font:13px ui-monospace,monospace;word-break:break-all}.s{color:var(--muted)}.op .in{padding:0 12px 12px}
        table{border-collapse:collapse;width:100%;font-size:14px;margin:8px 0}th,td{text-align:left;padding:6px 8px;border-bottom:1px solid var(--line);vertical-align:top}th{color:var(--muted);font-weight:500}
        dl.problems dt{margin-top:10px}dl.problems dd{margin:2px 0 0 0;color:var(--muted)}
        .empty{display:none;color:var(--muted);padding:24px 0}
        @media (max-width:860px){.wrap{grid-template-columns:1fr}nav{position:static;max-height:none;border-right:0;border-bottom:1px solid var(--line)}header input{max-width:none;min-width:0;width:100%}main{padding:16px}}
        </style>
        </head>
        <body>
        <header><h1>{$title}</h1><span class="base"><code>{$base}</code></span><a href="{$openApi}">openapi.json</a><input id="filter" type="search" placeholder="Filter operations, paths, scopes…" aria-label="Filter operations"></header>
        <div class="wrap">
        <nav aria-label="Resources">{$nav}<p class="group">Reference</p><ul><li><a href="#problems">Problems</a></li></ul></nav>
        <main>
        <section class="intro">{$intro}</section>
        {$body}
        <p class="empty" id="empty">Nothing matches that filter. Clear it to see every operation.</p>
        <section id="problems" class="tag"><h2>Problems</h2><p>Every failure is an RFC 9457 problem (<code>application/problem+json</code>). Its <code>error</code> is one of these codes, and its <code>type</code> links here.</p><dl class="problems">{$problems}</dl></section>
        </main>
        </div>
        <script nonce="{$nonce}">
        (function(){var f=document.getElementById('filter'),e=document.getElementById('empty');f.addEventListener('input',function(){var q=f.value.trim().toLowerCase(),any=false;document.querySelectorAll('details.op').forEach(function(d){var hit=!q||d.dataset.text.indexOf(q)!==-1;d.hidden=!hit;if(hit)any=true;});document.querySelectorAll('section.tag[data-tag]').forEach(function(s){s.hidden=!s.querySelector('details.op:not([hidden])');});e.style.display=any?'none':'block';});})();
        </script>
        </body>
        </html>
        HTML;
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function operation(string $method, string $path, array $operation): string
    {
        $summary = SpecNode::string($operation['summary'] ?? null);
        $description = SpecNode::string($operation['description'] ?? null);
        $text = mb_strtolower($method.' '.$path.' '.$summary.' '.$description);
        $rows = '';

        foreach (SpecNode::list($operation['parameters'] ?? null) as $parameter) {
            if (! is_array($parameter) || ! isset($parameter['name'])) {
                continue;
            }

            $rows .= '<tr><td><code>'.e(SpecNode::string($parameter['name'])).'</code></td><td>'.e(SpecNode::string($parameter['in'] ?? null)).'</td><td>'.(($parameter['required'] ?? false) === true ? 'yes' : '').'</td><td>'.self::inline(SpecNode::string($parameter['description'] ?? null)).'</td></tr>';
        }

        $documented = SpecNode::list($operation['responses'] ?? null);
        $responses = implode(' ', array_map(
            static fn (int|string $status, mixed $response): string => '<code>'.e((string) $status).'</code> '.e(SpecNode::string(SpecNode::map($response)['description'] ?? null)),
            array_keys($documented),
            array_values($documented),
        ));

        return '<details class="op" data-text="'.e($text).'"><summary><span class="m '.e($method).'">'.e($method).'</span><span class="p">'.e($path).'</span><span class="s">'.e($summary).'</span></summary><div class="in">'
            .self::markdown($description)
            .($rows !== '' ? '<table><thead><tr><th>Parameter</th><th>In</th><th>Required</th><th>About</th></tr></thead><tbody>'.$rows.'</tbody></table>' : '')
            .'<p class="s">Responses: '.$responses.'</p></div></details>';
    }

    private static function slug(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($value)), '-');
    }

    /** Escaped text with `code` and **bold**, nothing else. */
    private static function inline(string $text): string
    {
        $html = e($text);
        $html = (string) preg_replace('/`([^`]+)`/', '<code>$1</code>', $html);

        return (string) preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $html);
    }

    /** Paragraphs and bullet lists of {@see inline()} text. */
    private static function markdown(string $text): string
    {
        $html = '';

        foreach (preg_split('/\n\s*\n/', trim($text)) ?: [] as $block) {
            $lines = array_map('trim', explode("\n", trim($block)));

            if (str_starts_with($lines[0], '- ')) {
                $html .= '<ul>'.implode('', array_map(static fn (string $line): string => '<li>'.self::inline(ltrim(substr($line, 1))).'</li>', array_filter($lines, static fn (string $line): bool => str_starts_with($line, '- ')))).'</ul>';

                continue;
            }

            if (trim($block) !== '') {
                $html .= '<p>'.self::inline(implode(' ', $lines)).'</p>';
            }
        }

        return $html;
    }
}
