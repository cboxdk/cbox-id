<?php

declare(strict_types=1);

namespace App\Platform\Connect;

/**
 * THE QUICKSTARTS' CODE, ONCE — what the console's Get started shows for each framework,
 * block for block the code in `docs/quickstarts/{framework}.md`.
 *
 * The two used to be written separately and drifted the way two copies do: the console
 * told a Laravel app to set `CBOX_ID_REDIRECT_URI` (the SDK reads `CBOX_ID_REDIRECT`, so a
 * copied block answered 503), called id-go's `CreateAuthorizationRequest` and
 * `Authenticate` with signatures that do not compile, offered React a browser-only
 * sign-in that cannot work (the token endpoint sends no CORS headers), and wired Nuxt and
 * Python by hand instead of with their SDKs — while the docs, reviewed against each SDK,
 * said otherwise and carried notes telling readers to ignore the console.
 *
 * Every block here is VERBATIM a fenced block of that framework's quickstart page, and
 * `tests/Feature/Console/QuickstartSnippetsTest.php` fails the moment one is not — change
 * the page and this together. The environment block is the page's, less its file-name
 * comment, with the page's placeholders (`https://<environment>.cboxid.com`, `cid_…`,
 * `csec_…`) filled in for the app that was just created ({@see ConnectSnippets::quickstart()}).
 *
 * WHAT IS LEFT OUT is the page's sign-out step and its extras: Get started registers no
 * sign-out URI, and the page it links to has them.
 */
final class QuickstartSources
{
    /** The page's placeholders, which the console fills in for the app it created. */
    public const string ISSUER_PLACEHOLDER = 'https://<environment>.cboxid.com';

    public const string CLIENT_ID_PLACEHOLDER = 'cid_…';

    public const string CLIENT_SECRET_PLACEHOLDER = 'csec_…';

    public static function of(QuickstartFramework $framework): QuickstartSnippet
    {
        return match ($framework) {
            QuickstartFramework::NextJs => new QuickstartSnippet(
                install: [
                    new QuickstartBlock(<<<'SH'
                    npm install @cboxdk/id-js jose
                    SH),
                ],
                envFile: '.env.local',
                env: <<<'SH'
                    CBOX_ID_ISSUER=https://<environment>.cboxid.com
                    CBOX_ID_CLIENT_ID=cid_…
                    CBOX_ID_CLIENT_SECRET=csec_…
                    CBOX_ID_REDIRECT_URI=http://localhost:3000/auth/callback

                    # Read by the code below, not by the SDK:
                    CBOX_ID_POST_LOGOUT_REDIRECT_URI=http://localhost:3000/
                    SESSION_SECRET=<at least 32 random characters, e.g. from: openssl rand -base64 32>
                    SH,
                code: [
                    new QuickstartBlock(<<<'TS'
                    // lib/cbox.ts
                    import { createCboxId } from '@cboxdk/id-js/nextjs';

                    export const cboxId = createCboxId();
                    TS, 'lib/cbox.ts'),
                    new QuickstartBlock(<<<'TS'
                    // lib/session.ts
                    import { SignJWT, jwtVerify } from 'jose';
                    import { cookies } from 'next/headers';

                    const key = new TextEncoder().encode(process.env.SESSION_SECRET);

                    export type Session = {
                      sub: string;
                      email: string | null;
                      name: string | null;
                      idToken: string | null;
                    };

                    export function sealSession(session: Session): Promise<string> {
                      return new SignJWT({ ...session })
                        .setProtectedHeader({ alg: 'HS256' })
                        .setIssuedAt()
                        .setExpirationTime('8h')
                        .sign(key);
                    }

                    export async function getSession(): Promise<Session | null> {
                      const token = (await cookies()).get('session')?.value;
                      if (!token) return null;
                      try {
                        const { payload } = await jwtVerify(token, key);
                        return payload as unknown as Session;
                      } catch {
                        return null;
                      }
                    }
                    TS, 'lib/session.ts'),
                    new QuickstartBlock(<<<'TS'
                    // app/auth/sign-in/route.ts
                    import { cboxId } from '@/lib/cbox';

                    export const GET = () => cboxId.signIn();
                    TS, 'app/auth/sign-in/route.ts'),
                    new QuickstartBlock(<<<'TS'
                    // app/auth/callback/route.ts
                    import { NextResponse, type NextRequest } from 'next/server';
                    import { cboxId } from '@/lib/cbox';
                    import { sealSession } from '@/lib/session';

                    export async function GET(request: NextRequest) {
                      // Verifies state, PKCE and the id_token; throws InvalidStateError or AuthenticationError.
                      const user = await cboxId.callback(request);

                      const response = NextResponse.redirect(new URL('/dashboard', request.url));
                      response.cookies.set(
                        'session',
                        await sealSession({ sub: user.id, email: user.email, name: user.name, idToken: user.idToken }),
                        { httpOnly: true, sameSite: 'lax', secure: process.env.NODE_ENV === 'production', path: '/' },
                      );
                      return response;
                    }
                    TS, 'app/auth/callback/route.ts'),
                    new QuickstartBlock(<<<'TSX'
                    // app/dashboard/page.tsx
                    import { redirect } from 'next/navigation';
                    import { getSession } from '@/lib/session';

                    export default async function Dashboard() {
                      const session = await getSession();
                      if (!session) redirect('/auth/sign-in');

                      return (
                        <main>
                          <p>Signed in as {session.email}</p>
                          <a href="/auth/sign-out">Sign out</a>
                        </main>
                      );
                    }
                    TSX, 'app/dashboard/page.tsx'),
                ],
                run: [
                    new QuickstartBlock('npm run dev'),
                ],
            ),
            QuickstartFramework::React => new QuickstartSnippet(
                install: [
                    new QuickstartBlock(<<<'SH'
                    npm create vite@latest my-app -- --template react-ts
                    cd my-app
                    npm install @cboxdk/id-js @cboxdk/id-react express express-session
                    SH),
                ],
                envFile: '.env',
                env: <<<'SH'
                    CBOX_ID_ISSUER=https://<environment>.cboxid.com
                    CBOX_ID_CLIENT_ID=cid_…
                    CBOX_ID_REDIRECT_URI=http://localhost:5173/callback
                    CBOX_ID_POST_LOGOUT_REDIRECT_URI=http://localhost:5173/
                    SESSION_SECRET=<at least 32 random characters, e.g. from: openssl rand -base64 32>
                    # Only if you registered a Web app:
                    # CBOX_ID_CLIENT_SECRET=csec_…
                    SH,
                code: [
                    new QuickstartBlock(<<<'TS'
                    // vite.config.ts
                    import { defineConfig } from 'vite';
                    import react from '@vitejs/plugin-react';

                    export default defineConfig({
                      plugins: [react()],
                      server: {
                        proxy: {
                          '/auth': 'http://localhost:3001',
                          '/callback': 'http://localhost:3001',
                          '/api': 'http://localhost:3001',
                        },
                      },
                    });
                    TS, 'vite.config.ts'),
                    new QuickstartBlock(<<<'JS'
                    // server.mjs
                    import express from 'express';
                    import session from 'express-session';
                    import { CboxIdClient } from '@cboxdk/id-js';

                    const cbox = new CboxIdClient({
                      issuer: process.env.CBOX_ID_ISSUER,
                      clientId: process.env.CBOX_ID_CLIENT_ID,
                      clientSecret: process.env.CBOX_ID_CLIENT_SECRET, // undefined for a single-page app
                      redirectUri: process.env.CBOX_ID_REDIRECT_URI,
                    });

                    const app = express();
                    app.use(
                      session({
                        secret: process.env.SESSION_SECRET,
                        resave: false,
                        saveUninitialized: false,
                        cookie: { httpOnly: true, sameSite: 'lax' },
                      }),
                    );

                    // Steps 4–7 add routes here.

                    app.listen(3001);
                    JS, 'server.mjs'),
                    new QuickstartBlock(<<<'JS'
                    // server.mjs
                    app.get('/auth/sign-in', async (req, res) => {
                      const { url, state, codeVerifier, nonce } = await cbox.createAuthorizationRequest();
                      req.session.pending = { state, codeVerifier, nonce };
                      res.redirect(url);
                    });
                    JS, 'server.mjs'),
                    new QuickstartBlock(<<<'JS'
                    // server.mjs
                    app.get('/callback', async (req, res) => {
                      const user = await cbox.authenticate({
                        params: {
                          code: req.query.code,
                          state: req.query.state,
                          error: req.query.error,
                          error_description: req.query.error_description,
                        },
                        stored: req.session.pending ?? { state: '', codeVerifier: '', nonce: '' },
                      });

                      req.session.pending = undefined;
                      req.session.user = {
                        id: user.id,
                        email: user.email,
                        name: user.name,
                        organizationId: user.organizationId,
                      };
                      req.session.idToken = user.idToken;
                      res.redirect('/');
                    });

                    app.get('/api/me', (req, res) => {
                      res.json(req.session.user ?? null);
                    });
                    JS, 'server.mjs'),
                    new QuickstartBlock(<<<'TSX'
                    // src/App.tsx
                    import { useEffect, useState } from 'react';
                    import { CboxIdProvider, UserButton } from '@cboxdk/id-react';

                    type User = { id: string; email: string | null; name: string | null; organizationId: string | null };

                    export default function App() {
                      const [user, setUser] = useState<User | null | undefined>(undefined);

                      useEffect(() => {
                        fetch('/api/me').then((r) => r.json()).then(setUser);
                      }, []);

                      if (user === undefined) return null;

                      return (
                        <CboxIdProvider user={user} urls={{ signIn: '/auth/sign-in', signOut: '/auth/sign-out' }}>
                          <header>
                            <UserButton />
                          </header>
                          <main>{user ? <p>Signed in as {user.email}</p> : <p>You are signed out.</p>}</main>
                        </CboxIdProvider>
                      );
                    }
                    TSX, 'src/App.tsx'),
                ],
                run: [
                    new QuickstartBlock(<<<'SH'
                    node --env-file=.env --watch server.mjs
                    npm run dev
                    SH),
                ],
            ),
            QuickstartFramework::Laravel => new QuickstartSnippet(
                install: [
                    new QuickstartBlock(<<<'SH'
                    composer require cboxdk/laravel-id-client
                    php artisan vendor:publish --tag=cbox-id-client-config
                    SH),
                    new QuickstartBlock(<<<'SH'
                    php artisan make:migration add_cbox_id_to_users_table
                    SH),
                    new QuickstartBlock(<<<'PHP'
                    // database/migrations/xxxx_xx_xx_xxxxxx_add_cbox_id_to_users_table.php
                    public function up(): void
                    {
                        Schema::table('users', function (Blueprint $table) {
                            $table->string('cbox_id')->nullable()->unique();
                            $table->string('password')->nullable()->change();
                        });
                    }
                    PHP, 'database/migrations/…_add_cbox_id_to_users_table.php', note: 'Then add cbox_id to the User model\'s fillable attributes.'),
                    new QuickstartBlock(<<<'SH'
                    php artisan migrate
                    SH),
                ],
                envFile: '.env',
                env: <<<'ENV'
                    CBOX_ID_ISSUER=https://<environment>.cboxid.com
                    CBOX_ID_CLIENT_ID=cid_…
                    CBOX_ID_CLIENT_SECRET=csec_…
                    CBOX_ID_REDIRECT=http://localhost:8000/auth/callback
                    ENV,
                code: [
                    new QuickstartBlock(<<<'PHP'
                    // routes/web.php
                    use Cbox\Id\Client\Facades\CboxId;

                    Route::get('/auth/sign-in', fn () => CboxId::redirect())->name('login');
                    PHP, 'routes/web.php'),
                    new QuickstartBlock(<<<'PHP'
                    // routes/web.php
                    use App\Models\User;
                    use Illuminate\Http\Request;

                    Route::get('/auth/callback', function (Request $request) {
                        $cbox = CboxId::authenticate($request); // verifies state, PKCE and the id_token

                        $user = User::updateOrCreate(
                            ['cbox_id' => $cbox->id],           // the stable subject
                            ['email' => $cbox->email, 'name' => $cbox->name],
                        );

                        auth()->login($user);
                        $request->session()->regenerate();
                        $request->session()->put('cbox_id_token', $cbox->idToken);

                        return redirect()->intended('/dashboard');
                    });
                    PHP, 'routes/web.php'),
                    new QuickstartBlock(<<<'PHP'
                    // routes/web.php
                    Route::middleware('auth')->get('/dashboard', function () {
                        return 'Signed in as '.auth()->user()->email;
                    });
                    PHP, 'routes/web.php'),
                ],
                run: [
                    new QuickstartBlock('php artisan serve'),
                ],
            ),
            QuickstartFramework::Nuxt => new QuickstartSnippet(
                install: [
                    new QuickstartBlock(<<<'SH'
                    npm install @cboxdk/id-nuxt
                    SH),
                    new QuickstartBlock(<<<'TS'
                    // nuxt.config.ts
                    export default defineNuxtConfig({
                      modules: ['@cboxdk/id-nuxt'],
                    });
                    TS, 'nuxt.config.ts'),
                ],
                envFile: '.env',
                env: <<<'ENV'
                    CBOX_ID_ISSUER=https://<environment>.cboxid.com
                    CBOX_ID_CLIENT_ID=cid_…
                    CBOX_ID_CLIENT_SECRET=csec_…
                    CBOX_ID_REDIRECT_URI=http://localhost:3000/auth/callback
                    CBOX_ID_POST_LOGOUT_REDIRECT_URI=http://localhost:3000/
                    CBOX_ID_SESSION_PASSWORD=<at least 32 random characters, e.g. from: openssl rand -base64 32>
                    ENV,
                code: [
                    new QuickstartBlock(<<<'VUE'
                    <!-- app.vue -->
                    <template>
                      <header>
                        <!-- a sign-in button when signed out; avatar and account menu when signed in -->
                        <CboxUserButton />
                      </header>
                      <NuxtPage />
                    </template>
                    VUE, 'app.vue'),
                    new QuickstartBlock(<<<'TS'
                    // middleware/auth.ts
                    export default defineNuxtRouteMiddleware(() => {
                      const user = useCboxUser();
                      if (!user.value) {
                        return navigateTo('/auth/sign-in?redirect=' + encodeURIComponent(useRoute().fullPath), {
                          external: true,
                        });
                      }
                    });
                    TS, 'middleware/auth.ts'),
                    new QuickstartBlock(<<<'VUE'
                    <!-- pages/dashboard.vue -->
                    <script setup lang="ts">
                    definePageMeta({ middleware: 'auth' });
                    const user = useCboxUser();
                    </script>

                    <template>
                      <p>Signed in as {{ user?.email }}</p>
                    </template>
                    VUE, 'pages/dashboard.vue'),
                ],
                run: [
                    new QuickstartBlock('npm run dev'),
                ],
            ),
            QuickstartFramework::Go => new QuickstartSnippet(
                install: [
                    new QuickstartBlock(<<<'SH'
                    go mod init example.com/myapp
                    go get github.com/cboxdk/id-go
                    SH),
                ],
                envFile: '.env',
                env: <<<'SH'
                    CBOX_ID_ISSUER=https://<environment>.cboxid.com
                    CBOX_ID_CLIENT_ID=cid_…
                    CBOX_ID_CLIENT_SECRET=csec_…
                    CBOX_ID_REDIRECT_URI=http://localhost:8080/auth/callback
                    CBOX_ID_POST_LOGOUT_REDIRECT_URI=http://localhost:8080/
                    SH,
                code: [
                    new QuickstartBlock(<<<'GO'
                    // main.go
                    package main

                    import (
                    	"context"
                    	"crypto/rand"
                    	"errors"
                    	"log"
                    	"net/http"
                    	"os"
                    	"sync"

                    	cboxid "github.com/cboxdk/id-go"
                    )

                    // An in-memory session per browser, for this quickstart only.
                    type session struct {
                    	pending *cboxid.AuthorizationRequest
                    	user    *cboxid.CboxUser
                    }

                    var (
                    	mu       sync.Mutex
                    	sessions = map[string]*session{}
                    )

                    func current(w http.ResponseWriter, r *http.Request) *session {
                    	mu.Lock()
                    	defer mu.Unlock()
                    	if c, err := r.Cookie("sid"); err == nil {
                    		if s, ok := sessions[c.Value]; ok {
                    			return s
                    		}
                    	}
                    	id := rand.Text()
                    	s := &session{}
                    	sessions[id] = s
                    	http.SetCookie(w, &http.Cookie{Name: "sid", Value: id, Path: "/", HttpOnly: true, SameSite: http.SameSiteLaxMode})
                    	return s
                    }

                    func main() {
                    	client, err := cboxid.New(context.Background(), cboxid.Config{
                    		Issuer:       os.Getenv("CBOX_ID_ISSUER"),
                    		ClientID:     os.Getenv("CBOX_ID_CLIENT_ID"),
                    		ClientSecret: os.Getenv("CBOX_ID_CLIENT_SECRET"),
                    		RedirectURI:  os.Getenv("CBOX_ID_REDIRECT_URI"),
                    	})
                    	if err != nil {
                    		log.Fatal(err) // discovery runs here: a wrong issuer fails at start-up
                    	}

                    	// Steps 4–7 add handlers here.

                    	log.Fatal(http.ListenAndServe(":8080", nil))
                    }
                    GO, 'main.go'),
                    new QuickstartBlock(<<<'GO'
                    // in main()
                    http.HandleFunc("GET /auth/sign-in", func(w http.ResponseWriter, r *http.Request) {
                    	req, err := client.CreateAuthorizationRequest(cboxid.AuthParams{})
                    	if err != nil {
                    		http.Error(w, err.Error(), http.StatusInternalServerError)
                    		return
                    	}
                    	current(w, r).pending = &req
                    	http.Redirect(w, r, req.URL, http.StatusFound)
                    })
                    GO, 'main.go'),
                    new QuickstartBlock(<<<'GO'
                    // in main()
                    http.HandleFunc("GET /auth/callback", func(w http.ResponseWriter, r *http.Request) {
                    	s := current(w, r)
                    	if s.pending == nil {
                    		http.Redirect(w, r, "/auth/sign-in", http.StatusFound)
                    		return
                    	}
                    	q := r.URL.Query()
                    	user, err := client.Authenticate(r.Context(),
                    		cboxid.Callback{
                    			Code:             q.Get("code"),
                    			State:            q.Get("state"),
                    			Error:            q.Get("error"),
                    			ErrorDescription: q.Get("error_description"),
                    		},
                    		cboxid.Stored{State: s.pending.State, CodeVerifier: s.pending.CodeVerifier, Nonce: s.pending.Nonce},
                    	)
                    	s.pending = nil
                    	if errors.Is(err, cboxid.ErrInvalidState) {
                    		http.Redirect(w, r, "/auth/sign-in", http.StatusFound) // stale or forged: start again
                    		return
                    	}
                    	if err != nil {
                    		http.Error(w, err.Error(), http.StatusUnauthorized)
                    		return
                    	}
                    	s.user = user
                    	http.Redirect(w, r, "/dashboard", http.StatusFound)
                    })
                    GO, 'main.go'),
                    new QuickstartBlock(<<<'GO'
                    // at package level
                    func requireUser(next http.HandlerFunc) http.HandlerFunc {
                    	return func(w http.ResponseWriter, r *http.Request) {
                    		if current(w, r).user == nil {
                    			http.Redirect(w, r, "/auth/sign-in", http.StatusFound)
                    			return
                    		}
                    		next(w, r)
                    	}
                    }
                    GO, 'main.go'),
                    new QuickstartBlock(<<<'GO'
                    // in main()
                    http.HandleFunc("GET /dashboard", requireUser(func(w http.ResponseWriter, r *http.Request) {
                    	w.Write([]byte("Signed in as " + current(w, r).user.Email))
                    }))
                    GO, 'main.go'),
                ],
                run: [
                    new QuickstartBlock(<<<'SH'
                    set -a; . ./.env; set +a
                    SH),
                    new QuickstartBlock('go run .'),
                ],
            ),
            QuickstartFramework::Python => new QuickstartSnippet(
                install: [
                    new QuickstartBlock(<<<'SH'
                    python -m venv .venv && . .venv/bin/activate
                    pip install flask python-dotenv "cbox-id-client @ git+https://github.com/cboxdk/id-python@v0.9.0"
                    SH),
                ],
                envFile: '.env',
                env: <<<'ENV'
                    CBOX_ID_ISSUER=https://<environment>.cboxid.com
                    CBOX_ID_CLIENT_ID=cid_…
                    CBOX_ID_CLIENT_SECRET=csec_…
                    CBOX_ID_REDIRECT_URI=http://localhost:5000/auth/callback
                    CBOX_ID_POST_LOGOUT_REDIRECT_URI=http://localhost:5000/
                    FLASK_SECRET_KEY=<at least 32 random characters, e.g. from: openssl rand -base64 32>
                    ENV,
                code: [
                    new QuickstartBlock(<<<'PY'
                    # app.py
                    import os
                    from functools import wraps

                    from flask import Flask, redirect, request, session, url_for

                    from cbox_id import CboxIdClient, CboxIdConfig, InvalidStateError

                    app = Flask(__name__)
                    app.secret_key = os.environ["FLASK_SECRET_KEY"]

                    cbox = CboxIdClient(
                        CboxIdConfig(
                            issuer=os.environ["CBOX_ID_ISSUER"],
                            client_id=os.environ["CBOX_ID_CLIENT_ID"],
                            client_secret=os.environ["CBOX_ID_CLIENT_SECRET"],
                            redirect_uri=os.environ["CBOX_ID_REDIRECT_URI"],
                        )
                    )
                    PY, 'app.py'),
                    new QuickstartBlock(<<<'PY'
                    # app.py
                    @app.get("/auth/sign-in")
                    def sign_in():
                        req = cbox.create_authorization_request()
                        session["cbox"] = {"state": req.state, "verifier": req.code_verifier, "nonce": req.nonce}
                        return redirect(req.url)
                    PY, 'app.py'),
                    new QuickstartBlock(<<<'PY'
                    # app.py
                    @app.get("/auth/callback")
                    def callback():
                        pending = session.pop("cbox", None) or {}
                        try:
                            user = cbox.authenticate(
                                code=request.args.get("code"),
                                state=request.args.get("state"),
                                error=request.args.get("error"),
                                error_description=request.args.get("error_description"),
                                expected_state=pending.get("state"),
                                code_verifier=pending.get("verifier"),
                                nonce=pending.get("nonce"),
                            )
                        except InvalidStateError:
                            return redirect(url_for("sign_in"))  # stale or forged: start again

                        session["user"] = {"id": user.id, "email": user.email, "name": user.name}
                        session["id_token"] = user.id_token
                        return redirect(url_for("dashboard"))
                    PY, 'app.py'),
                    new QuickstartBlock(<<<'PY'
                    # app.py
                    def login_required(view):
                        @wraps(view)
                        def wrapped(*args, **kwargs):
                            if "user" not in session:
                                return redirect(url_for("sign_in"))
                            return view(*args, **kwargs)

                        return wrapped


                    @app.get("/dashboard")
                    @login_required
                    def dashboard():
                        return f"Signed in as {session['user']['email']}"
                    PY, 'app.py'),
                ],
                run: [
                    new QuickstartBlock('flask --app app run'),
                ],
            ),
        };
    }
}
