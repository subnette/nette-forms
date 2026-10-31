<?php declare(strict_types=1);

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

namespace Nette\Forms\Validation;

use Nette;
use Nette\Forms\{Control, Form};
use Nette\Forms\Controls\DateTimeControl;
use function is_array, is_bool, is_float, is_int, is_string, sprintf;


/**
 * Maps string operations (Form::Email, ':minLength'...) to the shared validator
 * objects implementing them, so that all rules get stable message identifiers
 * and a single evaluation path. Returns null when the operation or its argument
 * has no object counterpart; such rules run through the classic static validators.
 * @internal
 */
final class Operations
{
	use Nette\StaticClass;

	public static function toValidator(string $op, mixed $arg, Control $control): ?Validator
	{
		if ($control instanceof DateTimeControl && in_array($op, [Form::Equal, Form::Min, Form::Max, Form::Range], true)) {
			return null; // DateTimeControl compares in its own precision, ranges wrap around midnight
		}

		$isText = $control instanceof Nette\Forms\Controls\TextBase;
		// controls whose length rules mean the number of items, not characters
		$isMulti = !$isText && ($control instanceof Nette\Forms\Controls\MultiChoiceControl
			|| ($control instanceof Nette\Forms\Controls\UploadControl && $control->getControlPrototype()->multiple));

		return match ($op) {
			Form::Filled => new FilledValidator,
			Form::Blank => new BlankValidator,
			Form::Email => new EmailValidator,
			Form::URL => new UrlValidator,
			Form::Integer => new IntegerValidator,
			Form::Float => new FloatValidator,
			Form::Equal => new EqualValidator($arg),
			Form::Min => $arg !== null && self::isBound($arg) ? new RangeValidator(min: $arg) : null,
			Form::Max => $arg !== null && self::isBound($arg) ? new RangeValidator(max: $arg) : null,
			Form::Range => is_array($arg) && self::isBound($arg[0] ?? null) && self::isBound($arg[1] ?? null)
				? new RangeValidator($arg[0] ?? null, $arg[1] ?? null)
				: null,
			Form::MinLength => match (true) {
				!is_int($arg) => null,
				$isText => new LengthValidator(min: $arg),
				// for multi-value controls the length rules mean the number of items
				$isMulti => new ItemCountValidator(min: $arg),
				default => null,
			},
			Form::MaxLength => match (true) {
				!is_int($arg) => null,
				$isText => new LengthValidator(max: $arg),
				$isMulti => new ItemCountValidator(max: $arg),
				default => null,
			},
			Form::Length => match (true) {
				$isText && is_int($arg) => new LengthValidator($arg, $arg),
				$isText && self::isIntBounds($arg) => new LengthValidator($arg[0] ?? null, $arg[1] ?? null),
				$isMulti && is_int($arg) => new ItemCountValidator($arg, $arg),
				$isMulti && self::isIntBounds($arg) => new ItemCountValidator($arg[0] ?? null, $arg[1] ?? null),
				default => null,
			},
			Form::Pattern => is_string($arg) ? new PatternValidator($arg) : null,
			Form::PatternInsensitive => is_string($arg) ? new PatternValidator($arg, caseInsensitive: true) : null,
			Form::Enum => is_string($arg) && is_a($arg, \BackedEnum::class, allow_string: true) ? new EnumValidator($arg) : null,
			Form::MimeType => is_string($arg) || is_array($arg) ? new MimeTypeValidator($arg) : null,
			Form::Image => new ImageValidator,
			Form::MaxFileSize => is_int($arg) ? new FileSizeValidator($arg) : null,
			default => null,
		};
	}


	private static function isBound(mixed $value): bool
	{
		return $value === null
			|| is_int($value) || is_float($value) || is_string($value)
			|| $value instanceof \DateTimeInterface || $value instanceof Control;
	}


	private static function isIntBounds(mixed $value, bool $orControl = false): bool
	{
		if (!is_array($value)) {
			return false;
		}

		$isBound = fn($bound) => $bound === null || is_int($bound) || ($orControl && $bound instanceof Control);
		$min = $value[0] ?? null;
		$max = $value[1] ?? null;
		return $isBound($min) && $isBound($max) && ($min !== null || $max !== null);
	}


	/**
	 * Verifies the argument type of a string operation at declaration time, so that a mistyped
	 * argument fails in addRule() instead of a TypeError at submit time. A Control stands for its value.
	 */
	public static function assertArgument(string $op, mixed $arg, Control $control): void
	{
		$expected = match ($op) {
			':static' => is_bool($arg) ? null : 'bool',
			Form::Min, Form::Max => is_int($arg)
				|| is_float($arg)
				|| is_string($arg)
				|| $arg instanceof \DateTimeInterface
				|| $arg instanceof Control
				? null
				: 'int, float, string, date/time or Control',
			Form::Range => is_array($arg) ? null : 'array of two bounds',
			Form::MinLength, Form::MaxLength => is_int($arg) || $arg instanceof Control ? null : 'int',
			Form::Length => is_int($arg) || self::isIntBounds($arg, orControl: true) || $arg instanceof Control
				? null
				: 'int or array of two int bounds',
			Form::Pattern, Form::PatternInsensitive => is_string($arg) || $arg instanceof Control ? null : 'string pattern',
			Form::Enum => is_string($arg) || $arg instanceof Control ? null : 'class name of a backed enum',
			Form::MimeType => is_string($arg) || is_array($arg) || $arg instanceof Control ? null : 'string or array of strings',
			Form::MaxFileSize => is_int($arg) || $arg instanceof Control ? null : 'int (bytes)',
			default => null,
		};

		if ($expected !== null) {
			throw new Nette\InvalidArgumentException(sprintf(
				"Validation rule %s for control '%s' expects %s as the argument, %s given.",
				$op,
				(string) $control->getName(),
				$expected,
				get_debug_type($arg),
			));
		}
	}
}
