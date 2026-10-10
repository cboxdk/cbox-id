<?php

declare(strict_types=1);

namespace App\Actions\Radar;

use App\Platform\Actions\ActionContext;
use App\Platform\Actions\Input\Field;
use App\Platform\Radar\Enums\RadarAction;
use App\Platform\Radar\Enums\RadarField;
use App\Platform\Radar\Enums\RadarOperator;
use App\Platform\Radar\Enums\RadarRuleScope;
use App\Platform\Radar\RadarConditions;

/**
 * The fields a Radar rule is written with — the same for create and update, so the two
 * cannot accept different rules.
 */
final class RadarRuleFields
{
    /**
     * @return list<Field>
     */
    public static function body(bool $creating): array
    {
        $name = Field::string('name')->max(120)->describe('What the rule is for, as the console lists it.');
        $action = Field::string('action')->oneOf(RadarAction::values())->describe('What happens when every condition holds: `allow` (skip every rule after this one), `challenge` (a second factor on sign-in; a CAPTCHA or an emailed code on sign-up) or `block`.');
        $conditions = Field::list('conditions', Field::object('condition', [
            Field::string('field')->required()->oneOf(RadarField::values())->describe('The fact to test.'),
            Field::string('operator')->required()->oneOf(RadarOperator::values())->describe('How to compare it. Which operators a field takes depends on its type.'),
            Field::string('value')->max(255)->describe('The one value to compare with, as text — `DK`, `20`, `true`. For every operator except the list ones.'),
            Field::list('values', Field::string('value')->max(255))->max(RadarConditions::MAX_VALUES)->describe('The values to compare with, for `in`, `not_in`, `in_cidr` and `not_in_cidr`.'),
        ]))->min(1)->max(RadarConditions::MAX_CONDITIONS)->describe('Up to '.RadarConditions::MAX_CONDITIONS.' conditions; the rule matches when EVERY one holds. A fact that is unknown (no IP intelligence, no address on a passkey sign-in) matches no condition.');

        return [
            $creating ? $name->required() : $name,
            Field::string('description')->nullable()->max(500)->describe('Why the rule exists, for whoever reads it next.'),
            $creating ? $action->required() : $action,
            Field::string('applies_to')->oneOf(RadarRuleScope::values())->describe('`all` (the default), `sign_in` or `sign_up`.'),
            $creating ? $conditions->required() : $conditions,
            Field::boolean('enabled')->describe('Whether the rule is evaluated. Default true.'),
            Field::integer('position')->min(1)->describe('Where in the order to put it: 1 is evaluated first. Default: last.'),
        ];
    }

    /**
     * @return array<mixed>
     */
    public static function conditions(ActionContext $context): array
    {
        return $context->array('conditions');
    }

    public static function scope(ActionContext $context): ?RadarRuleScope
    {
        return RadarRuleScope::tryFrom($context->string('applies_to'));
    }

    public static function action(ActionContext $context): ?RadarAction
    {
        return RadarAction::tryFrom($context->string('action'));
    }
}
