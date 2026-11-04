<?php

namespace App\Enums;

use Illuminate\Validation\ValidationException;

enum HospitalDataSource: string
{
    case Development = 'development';
    case Imported = 'imported';
    case Verified = 'verified';

    /**
     * Marker for the records currently loaded into MediGuide.
     *
     * An unrecognized value stays development so placeholder clinics are
     * never presented as verified hospital information by accident.
     */
    public static function current(): self
    {
        $configured = config('hospital.data_source');

        if (! is_string($configured)) {
            return self::Development;
        }

        return self::tryFrom($configured) ?? self::Development;
    }

    /**
     * @return list<self>
     */
    public function allowedRecordSources(): array
    {
        return match ($this) {
            self::Development => [self::Development, self::Imported, self::Verified],
            self::Imported => [self::Imported, self::Verified],
            self::Verified => [self::Verified],
        };
    }

    /**
     * @return list<string>
     */
    public function allowedValues(): array
    {
        return array_map(fn (self $source): string => $source->value, $this->allowedRecordSources());
    }

    public function allows(self $recordSource): bool
    {
        return in_array($recordSource, $this->allowedRecordSources(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Development => 'Development Data',
            self::Imported => 'Hospital Provided',
            self::Verified => 'Hospital Verified',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Development => 0,
            self::Imported => 1,
            self::Verified => 2,
        };
    }

    public function isCompatibleWithParent(self $parent): bool
    {
        return $this->rank() <= $parent->rank();
    }

    /**
     * @return list<self>
     */
    public static function selectableCases(): array
    {
        return [self::Development, self::Imported];
    }

    /**
     * @return list<string>
     */
    public static function selectableValues(): array
    {
        return array_map(fn (self $source): string => $source->value, self::selectableCases());
    }

    public static function defaultForAdmin(?self $parent = null): self
    {
        $preferred = self::Imported;

        if ($parent instanceof self && ! $preferred->isCompatibleWithParent($parent)) {
            return $parent;
        }

        return $preferred;
    }

    public static function parse(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_string($value)) {
            $source = self::tryFrom($value);

            if ($source instanceof self) {
                return $source;
            }
        }

        throw ValidationException::withMessages([
            'data_source' => 'The selected hospital data source is invalid.',
        ]);
    }

    public static function assertAssignable(self $from, self $to): void
    {
        self::parse($to->value);

        if ($to === self::Verified && $from !== self::Verified) {
            throw ValidationException::withMessages([
                'data_source' => 'Hospital records cannot be promoted to verified automatically.',
            ]);
        }
    }
}
