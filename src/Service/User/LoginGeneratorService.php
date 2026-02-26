<?php

namespace App\Service\User;


class LoginGeneratorService
{
    public function generateLoginBase(string $lastname, string $firstname): string
    {
        $last = $this->transliterate($lastname);
        $first = $this->transliterate($firstname);

        $login = $last;
        if ($first !== '') {
            $login .= '.' . $first;
        }

        $login = preg_replace('/[^a-z0-9.]+/', '', $login) ?? '';
        $login = trim($login, '.');

        $login = $this->makeUniqueLogin($login);

        return $login;
    }

    private function transliterate(string $value): string
    {
        $map = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
            'е' => 'e', 'ё' => 'yo', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
            'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
            'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
            'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch',
            'ш' => 'sh', 'щ' => 'shch', 'ы' => 'y', 'э' => 'e', 'ю' => 'yu',
            'я' => 'ya', 'ь' => '', 'ъ' => '',
        ];

        $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';
        $value = mb_strtolower($value, 'UTF-8');
        $value = strtr($value, $map);
        $value = preg_replace('/[^a-z0-9]+/', '.', $value) ?? '';
        $value = preg_replace('/\.+/', '.', $value) ?? '';

        return trim($value, '.');
    }

    private function makeUniqueLogin(string $baseLogin): string
    {
        return $baseLogin . random_int(1000, 9999);
    }
}
