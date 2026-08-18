<?php

declare(strict_types=1);

namespace Tests;

trait Fixture
{
    protected function fixture(string $name): string
    {
        $path = __DIR__.'/fixtures/'.$name;

        if (! is_file($path)) {
            $this->fail("Fixture '{$name}' tidak ditemukan.");
        }

        return (string) file_get_contents($path);
    }

    /**
     * @param \App\DTO\FieldResult[] $fields
     * @return array<string,string>
     */
    protected function values(array $fields): array
    {
        $values = [];
        foreach ($fields as $field) {
            $values[$field->name] = $field->value;
        }

        return $values;
    }
}
