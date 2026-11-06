<?php

declare(strict_types=1);

namespace App\Providers;

use App\SignalsData\IdentityCapture;
use App\SignalsData\MaintainSource;
use App\SignalsData\SourceEvents;
use App\SignalsData\SourceLedger;
use App\SignalsData\SourceRefreshTokens;
use App\SignalsData\SourceRelationships;
use App\SignalsData\SourceSessions;
use App\SignalsData\SourceSubjects;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Erasure\DatabaseSubjectPseudonymiser;
use Cbox\Id\Kernel\Events\Contracts\EventBus;
use Cbox\Id\OAuthServer\Contracts\RefreshTokens;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

final class SignalsDataServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->commands([MaintainSource::class]);
    }

    public function register(): void
    {
        $this->app->bind(DatabaseSubjectPseudonymiser::class, static function (Application $app): DatabaseSubjectPseudonymiser {
            $subjects = $app->make(Subjects::class);

            return new DatabaseSubjectPseudonymiser($subjects instanceof SourceSubjects ? $subjects->underlying() : $subjects);
        });
        $this->app->extend(Subjects::class, static fn (Subjects $inner, Application $app): Subjects => new SourceSubjects($inner, $app->make(SourceLedger::class)));
        $this->app->extend(SessionManager::class, static fn (SessionManager $inner, Application $app): SessionManager => new SourceSessions(
            $inner, $app->make(SourceLedger::class), $app->make(IdentityCapture::class), static fn (): Request => $app->make(Request::class),
        ));
        $this->app->extend(RefreshTokens::class, static fn (RefreshTokens $inner, Application $app): RefreshTokens => new SourceRefreshTokens(
            $inner, $app->make(SourceLedger::class), $app->make(IdentityCapture::class), static fn (): Request => $app->make(Request::class),
        ));
        $this->app->extend(EventBus::class, static fn (EventBus $inner, Application $app): EventBus => new SourceEvents(
            $inner, $app->make(SourceLedger::class), $app->make(IdentityCapture::class), $app->make(SourceRelationships::class), static fn (): Request => $app->make(Request::class),
        ));
    }
}
