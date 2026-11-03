<?php

namespace Modules\UserModule\app\Contracts;

/**
 * Implemented by every model that owns a login in the users table (morph "userable").
 */
interface Userable
{
    // Why this user can't sign in right now, or null when the login is allowed.
    public function loginError(): ?string;

    // Route name to land on after login.
    public function homeRoute(): string;

    // Blade layout used by this user type's pages.
    public function layout(): string;

    public function displayName(): string;

    // Can he change his login email from "Login Details"? (false = only his password, e.g. employees)
    public function canChangeEmail(): bool;

    // The account whose data he works on (the account itself, or the account of an employee).
    public function ownerAccountId(): int;

    // His area: the route name prefix of his pages and the area the shared views render for (account | employee | accountant).
    // Views read it from the logged in user, not from the route (docs/accountant-plan.md §1.3).
    public function area(): string;
}
