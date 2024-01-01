<?php

namespace SailingDeveloper\LaravelGenerator\Generator\Model;

use App\Event\Enum\Generated\EventActionEnum;
use App\Event\Enum\Generated\EventVisibilityEnum;
use App\Event\Event\Event;
use SailingDeveloper\LaravelGenerator\Generator\Definition\ModelDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Definition\RelationDefinition;
use SailingDeveloper\LaravelGenerator\Generator\Generator;
use App\User\Model\UserModel;
use Illuminate\Support\Str;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\PhpNamespace;

/**
 * @author Thijs de Maa <maa.thijsde@gmail.com>
 *
 * @since 20231008
 */
class GeneratorEvent extends Generator
{
    public function __construct(private ModelDefinition $definition)
    {
    }

    public function generateEventIfNeeded(): void
    {
        if ($this->definition->relations->getWithEventOrNull() === null) {
            return;
        }

        $fileName = Str::of($this->definition->namespace->getName())
            ->explode('\\')
            ->slice(1)
            ->add('Event')
            ->add("{$this->definition->name}Event.php")
            ->join(DIRECTORY_SEPARATOR);

        if (file_exists(app_path($fileName))) {
            return;
        }

        $namespace = new PhpNamespace(
            Str::of($this->definition->namespace->getName())
                ->explode('\\')
                ->add('Event')
                ->join('\\'),
        );
        $class = $namespace->addClass($this->definition->name . 'Event');
        $namespace->addUse($namespace->getName() . '\\Generated\\' . $this->definition->name . 'EventBase');
        $class->setExtends($namespace->getName() . '\\Generated\\' . $this->definition->name . 'EventBase');
        $this->addClassHeader($class);

        $this->addMethodShouldCreateEventUpdate(
            $namespace,
            $class,
            isAbstract: false,
            todoComment: "Determine whether a new event should be created when the model is updated.\n// If so, the old events will be deleted, meaning the event will “bubble up.”\n// This is not always the best experience, so it’s best to check whether a specific property has changed, like a status.",
        );
        $this->addMethodDetermineVisibility($namespace, $class, isAbstract: false);
        $this->addMethodDetermineString($namespace, $class, name: 'emoji', isAbstract: false);
        $this->addMethodDetermineString($namespace, $class, name: 'title', isAbstract: false);
        $this->addMethodDetermineString($namespace, $class, name: 'description', isAbstract: false);

        $this->writeNamespaceToFile($fileName, $namespace);
    }

    public function generateEventBaseIfNeeded(): void
    {
        $relationEvent = $this->definition->relations->getWithEventOrNull();

        if ($relationEvent === null) {
            return;
        }

        $namespace = new PhpNamespace(
            Str::of($this->definition->namespace->getName())
                ->explode('\\')
                ->add('Event')
                ->add('Generated')
                ->join('\\'),
        );
        $class = $namespace->addClass($this->definition->name . 'EventBase');
        $class->setAbstract();
        $namespace->addUse(Event::class);
        $class->setExtends(Event::class);

        $this->addClassHeaderGenerator($class);

        $namespace->addUse($this->definition->getFullClassName());
        $namespace->addUse(EventActionEnum::class);

        $this->addConstructor($namespace, $class, $relationEvent);
        $this->addMethodShouldCreateEvent($namespace, $class, isAbstract: false);
        $this->addMethodShouldCreateEventCreate($namespace, $class, isAbstract: false);
        $this->addMethodShouldCreateEventUpdate($namespace, $class, isAbstract: true);
        $this->addMethodShouldSendNotification($namespace, $class, isAbstract: false);
        $this->addMethodShouldDeleteAllOldEvent($namespace, $class, isAbstract: false);
        $this->addMethodDetermineVisibility($namespace, $class, isAbstract: true);
        $this->addMethodDetermineString($namespace, $class, name: 'emoji', isAbstract: true);
        $this->addMethodDetermineString($namespace, $class, name: 'title', isAbstract: true);
        $this->addMethodDetermineString($namespace, $class, name: 'description', isAbstract: true);

        $fileName = Str::of($this->definition->namespace->getName())
            ->explode('\\')
            ->slice(1)
            ->add('Event')
            ->add('Generated')
            ->add("{$this->definition->name}EventBase.php")
            ->join(DIRECTORY_SEPARATOR);

        $this->writeNamespaceToFile($fileName, $namespace);
    }

    private function addConstructor(PhpNamespace $namespace, ClassType $class, RelationDefinition $relationEvent): void
    {
        $constructor = $class->addMethod('__construct');
        $constructor->addPromotedParameter('model')
            ->setType($this->definition->getFullClassName());
        $constructor->addPromotedParameter('action')
            ->setType(EventActionEnum::class);
        $constructor->addPromotedParameter('owner')
            ->setType(UserModel::class)
            ->setNullable();
    }

    private function addMethodShouldCreateEvent(PhpNamespace $namespace, ClassType $class, bool $isAbstract): void
    {
        $namespace->addUse($this->definition->getFullClassName());

        $method = $class->addMethod('shouldCreateEvent');
        $method->setReturnType('bool');
        $method->setAbstract($isAbstract);
        $method->addParameter(Str::camel($this->definition->name))
            ->setType($this->definition->getFullClassName());

        if ($isAbstract) {
            // Don’t generate event body.
        } else {
            $body = 'return match ($this->action) {' . PHP_EOL;
            $body .= $this->indent(1) . 'EventActionEnum::CREATE => $this->shouldCreateEventCreate($' . Str::camel($this->definition->name) . '),' . PHP_EOL;
            $body .= $this->indent(1) . 'EventActionEnum::UPDATE => $this->shouldCreateEventUpdate($' . Str::camel($this->definition->name) . '),' . PHP_EOL;
            $body .= '};' . PHP_EOL;

            $method->addBody($body);
        }
    }

    private function addMethodShouldCreateEventCreate(PhpNamespace $namespace, ClassType $class, bool $isAbstract): void
    {
        $namespace->addUse($this->definition->getFullClassName());

        $method = $class->addMethod('shouldCreateEventCreate');
        $method->setReturnType('bool');
        $method->setAbstract($isAbstract);
        $method->addParameter(Str::camel($this->definition->name))
            ->setType($this->definition->getFullClassName());

        if ($isAbstract) {
            // Don’t generate event body.
        } else {
            $method->addBody('return true;');
        }
    }

    private function addMethodShouldCreateEventUpdate(
        PhpNamespace $namespace,
        ClassType $class,
        bool $isAbstract,
        string $todoComment = null,
    ): void {
        $namespace->addUse($this->definition->getFullClassName());

        $method = $class->addMethod('shouldCreateEventUpdate');
        $method->setReturnType('bool');
        $method->setAbstract($isAbstract);
        $method->addParameter(Str::camel($this->definition->name))
            ->setType($this->definition->getFullClassName());

        if ($isAbstract) {
            // Don’t generate event body.
        } else {
            $method->addBody('// TODO: ' . ($todoComment ?? 'Implement.'));
        }
    }

    private function addMethodShouldSendNotification(
        PhpNamespace $namespace,
        ClassType $class,
        bool $isAbstract,
        string $todoComment = null,
    ): void {
        $namespace->addUse($this->definition->getFullClassName());

        $method = $class->addMethod('shouldSendNotification');
        $method->setReturnType('bool');
        $method->setAbstract($isAbstract);
        $method->addParameter(Str::camel($this->definition->name))
            ->setType($this->definition->getFullClassName());

        if ($isAbstract) {
            // Don’t generate event body.
        } else {
            $body = 'return $this->owner instanceof UserModel ' . PHP_EOL;
            $body .= $this->indent(1) . '&& $this->shouldCreateEvent($' . Str::camel($this->definition->name) . ');';

            $method->addBody($body);
        }
    }

    private function addMethodShouldDeleteAllOldEvent(PhpNamespace $namespace, ClassType $class, bool $isAbstract): void
    {
        $namespace->addUse($this->definition->getFullClassName());

        $method = $class->addMethod('shouldDeleteAllOldEvent');
        $method->setReturnType('bool');
        $method->setAbstract($isAbstract);
        $method->addParameter(Str::camel($this->definition->name))
            ->setType($this->definition->getFullClassName());

        if ($isAbstract) {
            // Don’t generate event body.
        } else {
            $method->addBody('return $this->shouldCreateEvent($' . Str::camel($this->definition->name) . ');');
        }
    }

    private function addMethodDetermineVisibility(PhpNamespace $namespace, ClassType $class, bool $isAbstract): void
    {
        $namespace->addUse($this->definition->getFullClassName());
        $namespace->addUse(EventVisibilityEnum::class);

        $method = $class->addMethod('determineVisibility');
        $method->setReturnType(EventVisibilityEnum::class);
        $method->setAbstract($isAbstract);
        $method->addParameter(Str::camel($this->definition->name))
            ->setType($this->definition->getFullClassName());
        if ($isAbstract) {
            // Don’t generate event body.
        } else {
            $method->addBody('return EventVisibilityEnum::PRIVATE;');
        }
    }

    private function addMethodDetermineString(
        PhpNamespace $namespace,
        ClassType $class,
        string $name,
        bool $isAbstract,
        string $todoComment = null,
    ): void {
        $namespace->addUse($this->definition->getFullClassName());
        $namespace->addUse(UserModel::class);

        $method = $class->addMethod('determine' . ucfirst($name));
        $method->setReturnType('string');
        $method->setAbstract($isAbstract);
        $method->addParameter(Str::camel($this->definition->name))
            ->setType($this->definition->getFullClassName());
        $method->addParameter('userAuthenticated')
            ->setType(UserModel::class)
            ->setNullable();

        if ($isAbstract) {
            // Don’t generate event body.
        } else {
            $method->addBody('// TODO: ' . ($todoComment ?? 'Implement.'));
        }
    }
}
