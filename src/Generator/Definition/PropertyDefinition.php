<?php

namespace SailingDeveloper\LaravelGenerator\Generator\Definition;

use App\Address\Cast\AddressCast;
use App\Address\Object\Address;
use App\Emoji\Rule\Emoji;
use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\PropertyTypeEnum;
use SailingDeveloper\LaravelGenerator\Generator\Definition\Enum\RequestTypeEnum;
use App\Geolocation\Cast\GeolocationCast;
use App\Geolocation\Object\Geolocation;
use App\Nova\Fields\NovaEnum;
use App\Point\Cast\PointCast;
use App\Point\Object\Point;
use App\Request\Rule\PhoneNumberRule;
use App\Rules\HexadecimalColor;
use Ebess\AdvancedNovaMediaLibrary\Fields\Files;
use Ebess\AdvancedNovaMediaLibrary\Fields\Images;
use Ebess\AdvancedNovaMediaLibrary\Fields\Media;
use Exception;
use Illuminate\Support\Carbon;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection;

/**
 * @author Thijs de Maa <maa.thijsde@gmail.com>
 *
 * @since 20230504
 */
class PropertyDefinition extends Definition
{
    /**
     * @param array<int, string> $rules
     */
    public function __construct(
        string $name,
        public ModelDefinition $modelDefinition,
        public PropertyTypeEnum $type,
        public string $label,
        public bool $isRequired,
        public bool $isComputed,
        public array $rules,
        public RequestDefinition $requestDefinition,
        public NovaDefinition $novaPropertyDefinition,
        public ?string $index,
        public bool $isInherited = false,
        public bool $isFromRelation = false,
    ) {
        parent::__construct($name);
    }

    public function toPhpDocType(): string
    {
        return match ($this->type) {
            PropertyTypeEnum::ID,
            PropertyTypeEnum::INTEGER => 'int',
            PropertyTypeEnum::ULID,
            PropertyTypeEnum::STRING => 'string',
            PropertyTypeEnum::TEXT => 'string',
            PropertyTypeEnum::TIMESTAMP => Carbon::class,
            PropertyTypeEnum::JSON_OBJECT => 'object',
            PropertyTypeEnum::JSON_ARRAY => 'array',
            PropertyTypeEnum::GEOLOCATION => Geolocation::class,
            PropertyTypeEnum::POINT => Point::class,
            PropertyTypeEnum::FILE,
            PropertyTypeEnum::IMAGE,
            PropertyTypeEnum::VIDEO => \Spatie\MediaLibrary\MediaCollections\Models\Media::class,
            PropertyTypeEnum::FILE_COLLECTION,
            PropertyTypeEnum::IMAGE_COLLECTION,
            PropertyTypeEnum::VIDEO_COLLECTION => MediaCollection::class,
            PropertyTypeEnum::ADDRESS => Address::class,
            PropertyTypeEnum::ENUM => throw new Exception(
                sprintf('Enum property should be instance of "%s"', EnumPropertyDefinition::class)
            ),
        };
    }

    public function toCastType(): string
    {
        return match ($this->type) {
            PropertyTypeEnum::ID,
            PropertyTypeEnum::INTEGER => 'int',
            PropertyTypeEnum::ULID,
            PropertyTypeEnum::STRING => 'string',
            PropertyTypeEnum::TEXT => 'string',
            PropertyTypeEnum::TIMESTAMP => 'datetime',
            PropertyTypeEnum::JSON_OBJECT => 'object',
            PropertyTypeEnum::JSON_ARRAY => 'array',
            PropertyTypeEnum::GEOLOCATION => GeolocationCast::class,
            PropertyTypeEnum::POINT => PointCast::class,
            PropertyTypeEnum::ADDRESS => AddressCast::class,
            PropertyTypeEnum::ENUM => throw new Exception(
                sprintf('Enum property should be instance of "%s"', EnumPropertyDefinition::class)
            ),
        };
    }

    public function toNovaType(): string
    {
        if ($this->novaPropertyDefinition->type) {
            return 'Laravel\Nova\Fields\\' . $this->novaPropertyDefinition->type;
        } else {
            return match ($this->type) {
                PropertyTypeEnum::ID => ID::class,
                PropertyTypeEnum::ULID,
                PropertyTypeEnum::STRING, => Text::class,
                PropertyTypeEnum::TEXT => Textarea::class,
                PropertyTypeEnum::INTEGER => Number::class,
                PropertyTypeEnum::ENUM => NovaEnum::class,
                PropertyTypeEnum::JSON_OBJECT,
                PropertyTypeEnum::JSON_ARRAY => Code::class,
                PropertyTypeEnum::TIMESTAMP => DateTime::class,
                PropertyTypeEnum::FILE,
                PropertyTypeEnum::FILE_COLLECTION => Files::class,
                PropertyTypeEnum::IMAGE,
                PropertyTypeEnum::IMAGE_COLLECTION => Images::class,
                PropertyTypeEnum::VIDEO,
                PropertyTypeEnum::VIDEO_COLLECTION => Media::class,
                PropertyTypeEnum::GEOLOCATION => \Climbingatlas\NovaFieldGeolocation\Geolocation::class,
                PropertyTypeEnum::POINT => \Climbingatlas\NovaFieldPoint\Point::class,
                PropertyTypeEnum::ADDRESS => \Climbingatlas\NovaFieldAddress\Address::class,
            };
        }
    }

    public function toColumnType(): string
    {
        return match ($this->type) {
            PropertyTypeEnum::ID => match ($this->name) {
                'id' => 'id',
                default => 'unsignedBigInteger',
            },
            PropertyTypeEnum::ULID => 'ulid',
            PropertyTypeEnum::TIMESTAMP => 'timestamp',
            PropertyTypeEnum::STRING => 'string',
            PropertyTypeEnum::TEXT => 'longText',
            PropertyTypeEnum::INTEGER => 'integer',
            PropertyTypeEnum::JSON_OBJECT,
            PropertyTypeEnum::JSON_ARRAY,
            PropertyTypeEnum::GEOLOCATION,
            PropertyTypeEnum::POINT,
            PropertyTypeEnum::ADDRESS => 'json',
            PropertyTypeEnum::ENUM => throw new Exception(
                sprintf('Enum property should be instance of "%s"', EnumPropertyDefinition::class)
            ),
        };
    }

    public function generateGetter(string $variable): string
    {
        switch ($this->type) {
            case PropertyTypeEnum::ENUM:
                if ($this->isRequired) {
                    return "{$variable}->{$this->name}->name";
                } else {
                    return "{$variable}->{$this->name}?->name";
                }
            default:
                return "{$variable}->{$this->name}";
        }
    }

    /**
     * @return array<int, string|class-string>
     */
    public function getRules(RequestTypeEnum $requestType): array
    {
        return [
            ...$this->determineRulesRequired($requestType),
            ...$this->determineRulesByPropertyType(),
            ...$this->generateRules(),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function determineRulesRequired(RequestTypeEnum $requestType): array
    {
        if ($requestType === RequestTypeEnum::CREATE && $this->requestDefinition->isRequired) {
            return ['required'];
        } else {
            return ['nullable'];
        }
    }

    /**
     * @return array<int, string>
     */
    private function determineRulesByPropertyType(): array
    {
        return match ($this->type) {
            PropertyTypeEnum::ID,
            PropertyTypeEnum::ULID => ['ulid'],
            PropertyTypeEnum::INTEGER => ['integer'],
            PropertyTypeEnum::STRING => ['string', 'max:255'],
            PropertyTypeEnum::TEXT => ['string'],
            PropertyTypeEnum::TIMESTAMP => ['date'],
            PropertyTypeEnum::GEOLOCATION => ['array'],
            PropertyTypeEnum::POINT => ['array'],
            PropertyTypeEnum::ADDRESS => ['array'],
            PropertyTypeEnum::JSON_OBJECT,
            PropertyTypeEnum::JSON_ARRAY,
            PropertyTypeEnum::FILE,
            PropertyTypeEnum::FILE_COLLECTION,
            PropertyTypeEnum::IMAGE,
            PropertyTypeEnum::IMAGE_COLLECTION,
            PropertyTypeEnum::VIDEO,
            PropertyTypeEnum::VIDEO_COLLECTION => [],
            PropertyTypeEnum::ENUM => throw new Exception(
                sprintf('Enum property should be instance of "%s"', EnumPropertyDefinition::class),
            ),
        };
    }

    /**
     * @return array<int, string|class-string>
     */
    private function generateRules(): array
    {
        return array_map(
            fn (string $rule): string => match ($rule) {
                'phone_number' => PhoneNumberRule::class,
                'color' => HexadecimalColor::class,
                'emoji' => Emoji::class,
                default => $rule,
            },
            $this->rules,
        );
    }

    public function isMedia(): bool
    {
        return in_array($this->type, [
            PropertyTypeEnum::FILE,
            PropertyTypeEnum::FILE_COLLECTION,
            PropertyTypeEnum::IMAGE,
            PropertyTypeEnum::IMAGE_COLLECTION,
            PropertyTypeEnum::VIDEO,
            PropertyTypeEnum::VIDEO_COLLECTION,
        ]);
    }

    public function isMediaCollection(): bool
    {
        return in_array($this->type, [
            PropertyTypeEnum::FILE_COLLECTION,
            PropertyTypeEnum::IMAGE_COLLECTION,
            PropertyTypeEnum::VIDEO_COLLECTION,
        ]);
    }
}
