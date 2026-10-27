<?php

declare(strict_types=1);

namespace App\Tests\Service\OnlyOffice;

use App\Service\OnlyOffice\EditorSessionKey;
use PHPUnit\Framework\TestCase;

/** Повторное открытие после «Сохранить» не должно получать ключ уже записанной сессии. */
final class EditorSessionKeyTest extends TestCase
{
    public function testSavedKeyIsReplacedWhenNobodyIsEditing(): void
    {
        $stored = ['key' => 'df1-old', 'at' => time(), 'saved' => true];

        self::assertSame('df1-new', EditorSessionKey::next($stored, true, false, static fn (): string => 'df1-new'));
        self::assertTrue(EditorSessionKey::forget($stored, 'df1-old', time()));
    }

    public function testSavedKeyStaysWhileEditorsRemain(): void
    {
        $stored = ['key' => 'df1-old', 'at' => time(), 'saved' => true];

        self::assertSame('df1-old', EditorSessionKey::next($stored, true, true, static fn (): string => 'df1-new'));
    }

    public function testUnsavedKeySurvivesALateClose(): void
    {
        $stored = ['key' => 'df1-old', 'at' => time(), 'saved' => false];

        self::assertSame('df1-old', EditorSessionKey::next($stored, true, false, static fn (): string => 'df1-new'));
        self::assertFalse(EditorSessionKey::forget($stored, 'df1-old', time()));
        $idle = ['key' => 'df1-old', 'at' => time() - EditorSessionKey::KEEP_SECONDS - 1, 'saved' => false];
        self::assertSame('df1-new', EditorSessionKey::next($idle, true, false, static fn (): string => 'df1-new'));
        self::assertTrue(EditorSessionKey::wantsFresh('{"refresh":true}'));
        self::assertFalse(EditorSessionKey::wantsFresh(''));
        self::assertTrue(EditorSessionKey::forget(
            ['key' => 'df1-old', 'at' => time() - EditorSessionKey::KEEP_SECONDS - 1, 'saved' => false],
            'df1-old',
            time(),
        ));
    }

    public function testForeignOrMissingKeyIsKept(): void
    {
        $stored = ['key' => 'df1-old', 'at' => 0, 'saved' => true];

        self::assertFalse(EditorSessionKey::forget($stored, 'other', time()));
        self::assertFalse(EditorSessionKey::forget(null, 'df1-old', time()));
    }

    public function testViewerDoesNotReuseTheEditorKey(): void
    {
        $stored = ['key' => 'df1-old', 'at' => time(), 'saved' => false];

        self::assertSame('df1-new', EditorSessionKey::next($stored, false, true, static fn (): string => 'df1-new'));
    }

    public function testInfoWithoutEditorsIsFree(): void
    {
        self::assertFalse(EditorSessionKey::busy(['error' => 0, 'users' => []]));
        self::assertFalse(EditorSessionKey::busy(['error' => 1]));
        self::assertTrue(EditorSessionKey::busy(['error' => 0, 'users' => ['7']]));
        self::assertTrue(EditorSessionKey::busy(['error' => 1, 'unreachable' => true]));
    }
}
