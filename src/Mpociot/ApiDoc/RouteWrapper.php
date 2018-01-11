<?php

namespace Mpociot\ApiDoc;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Mpociot\ApiDoc\Exceptions\ClosureRouteException;
use Mpociot\ApiDoc\Exceptions\NoTypeSpecifiedException;
use Mpociot\Reflection\DocBlock\Tag;
use ReflectionClass;
use ReflectionFunctionAbstract;
use ReflectionParameter;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;

class RouteWrapper
{
    /**
     * Original route object.
     *
     * @var Route
     */
    protected $route;

    /**
     * Parsed action array.
     *
     * @var string[2]
     */
    protected $parsedAction;

    /**
     * Parsed FormRequest's reflection.
     *
     * @var ReflectionClass
     */
    protected $parsedFormRequest;

    /**
     * Parsed doc block for controller.
     *
     * @var DocBlockWrapper
     */
    protected $controllerDockBlock;

    /**
     * Parsed doc block for method.
     *
     * @var DocBlockWrapper
     */
    protected $methodDocBlock;

    /**
     * Injected array of options from instance.
     *
     * @var array
     */
    protected $options;

    /**
     * RouteWrapper constructor.
     *
     * @param Route $route
     * @param array $options
     */
    public function __construct($route, $options)
    {
        $this->route = $route;
        $this->options = $options;
    }

    /**
     * Parse the route and return summary information for it.
     *
     * @return array
     */
    public function getSummary()
    {
        return [
            'id' => $this->getSignature(),
            'resource' => $this->getResourceName(),
            'uri' => $this->getUri(),
            'methods' => $this->getMethods(),
            'title' => $this->getMethodDocBlock()->getShortDescription(),
            'description' => $this->getMethodDocBlock()->getLongDescription()->getContents(),
            'parameters' => [
                'path' => $this->getPathParameters(),
                'query' => $this->getQueryParameters()
            ],
            'responses' => $this->getResponses()
        ];
    }

    /**
     * Returns route's unique signature.
     *
     * @return string
     */
    public function getSignature()
    {
        return md5( $this->getUri() . ':' . implode($this->getMethods()) );
    }

    /**
     * Returns route's name.
     *
     * @return string
     */
    public function getName()
    {
        return $this->route->getName();
    }

    /**
     * Returns route's HTTP methods.
     *
     * @return array
     */
    public function getMethods()
    {
        if (version_compare(app()->version(), '5.4', '<')) {
            return $this->route->getMethods();
        }

        return $this->route->methods();
    }

    /**
     * Returns route's URI.
     *
     * @return string
     */
    public function getUri()
    {
        if (version_compare(app()->version(), '5.4', '<')) {
            return $this->route->getUri();
        }

        return $this->route->uri();
    }

    /**
     * Returns route's action string.
     *
     * @return string
     * @throws ClosureRouteException
     */
    public function getAction()
    {
        if(! $this->isSupported()) {
            throw new ClosureRouteException('Closure callbacks are not supported. Please use a controller method.');
        }

        return $this->getActionSafe();
    }

    /**
     * Returns route's action string safe (without any exceptions).
     *
     * @return string
     */
    public function getActionSafe()
    {
        return $this->route->getActionName();
    }

    /**
     * Checks if the route is supported.
     *
     * @return boolean
     */
    public function isSupported()
    {
        return isset($this->route->getAction()['controller']);
    }

    /**
     * Parse the action and return it.
     *
     * @return string[2]
     */
    protected function parseAction()
    {
        if(! $this->parsedAction) {
            return $this->parsedAction = explode('@', $this->getAction(), 2);
        }

        return $this->parsedAction;
    }

    /**
     * Parses path parameters and returns them.
     *
     * @return array
     */
    public function getPathParameters()
    {
        preg_match_all('/\{(.*?)\}/', $this->getUri(), $matches);
        $pathParameters = $matches[1];

        if(empty($pathParameters))
            return [];

        $methodParameters = $this->getMethodReflection()->getParameters();

        return array_map(function($pathParameter) use ($methodParameters) {
            $name = trim($pathParameter, '?');
            /** @var ReflectionParameter $methodParameter */
            $methodParameter = array_first($methodParameters, function($methodParameter) use ($name) {
                /** @var ReflectionParameter $methodParameter */
                return strtolower($methodParameter->getName()) === strtolower($name);
            });

            $type = null;
            $default = null;
            $description = '';

            if($methodParameter) {
                $parameterType = $methodParameter->getType();
                if (!$parameterType && !$this->options['skipTypeChecks']) {
                    throw new NoTypeSpecifiedException("No type specified for parameter `$name`");
                }

                if ($parameterType) {
                    if ($parameterType->isBuiltin()) {
                        $type = strval($parameterType);
                    } elseif ($parameterClass = $methodParameter->getClass()) {
                        $type = $parameterClass->getShortName();

                        if ($parameterClass->isSubclassOf(Model::class)) {
                            $description = "`$type` id";
                            $type = 'model_id';
                        }
                    }
                }

                if($methodParameter->isOptional()) {
                    $default = $methodParameter->getDefaultValue();
                }
            }

            $rules = [];

            if($name === $pathParameter) {
                $rules[] = 'required';
            }
            if(isset($this->route->wheres[$name])) {
                $rules[] = 'regex:' . $this->route->wheres[$name];
            }

            // TODO remake
            return [
                'name' => $name,
                'required' => ($name === $pathParameter), // trimmed nothing
                'type' => $type,
                'default' => $default,
                'rules' => $rules,
                'description' => $description
            ];
        }, $matches[1]);
    }

    /**
     * Parses validation rules and converts them into an array of parameters.
     *
     * @return array
     */
    public function getQueryParameters()
    {
        $params = [];

        foreach($this->getQueryValidationRules() as $name => $rules) {
            $params[] = [
                'name' => $name,
                'required' => false,
                'type' => '',
                'default' => '',
                'rules' => $rules,
                'description' => ''
            ];
        }

        return $params;
    }

    /**
     * Return an array of query validation rules.
     *
     * @return array
     */
    protected function getQueryValidationRules()
    {
        if (! ($formRequestReflection = $this->getFormRequestClassReflection())) {
            return [];
        }

        $className = $formRequestReflection->getName();

        /** @var FormRequest $formRequest */
        $formRequest = new $className;
        $formRequest->setContainer(app());

        if($formRequestReflection->hasMethod('validator')) {
            $factory = app()->make(ValidationFactory::class);
            $validator = app()->call([$formRequest, 'validator'], [$factory]);

            $property = (new ReflectionClass($validator))->getProperty('initialRules');
            $property->setAccessible(true);

            $rules = $property->getValue();
        } else {
            $rules = app()->call([$formRequest, 'rules']);
        }

        $rules = array_map(function($rule) {
            if (is_string($rule)) {
                return explode('|', $rule);
            } elseif (is_object($rule)) {
                return [strval($rule)];
            } else {
                return array_map(function($rule) {
                    return is_object($rule) ? strval($rule) : $rule;
                }, $rule);
            }
        }, $rules);

        return $rules;
    }

    /**
     * Returns route's resource name.
     *
     * @return string
     */
    public function getResourceName()
    {
        return $this->getDocBlocks()
            ->map(function($docBlock) {
                /** @var DocBlockWrapper $docBlock */
                $tag = $docBlock->getDocTag('resource');

                if(! $tag) {
                    return null;
                }

                return $tag->getContent();
            })
            ->filter()
            ->first(null, 'UnclassifiedRoutes');
    }

    /**
     * Returns all route's responses.
     *
     * @return array
     */
    public function getResponses()
    {
        return $this->getMethodDocBlock()
            ->getDocTags('response')
            ->map(function($tag) {
                /** @var Tag $tag */
                return $tag->getContent();
            })
            ->toArray();
    }

    /**
     * Checks if the route is hidden from docs by annotation.
     *
     * @return boolean
     */
    public function isHiddenFromDocs()
    {
        return $this->getDocBlocks()
            ->contains(function($docBlock) {
                /** @var DocBlockWrapper $docBlock */
                return $docBlock->hasDocTag('docsHide');
            });
    }

    /**
     * Get all doc blocks.
     *
     * @return Collection|DocBlockWrapper[]
     */
    protected function getDocBlocks()
    {
        return collect([$this->getMethodDocBlock(), $this->getControllerDocBlock()]);
    }

    /**
     * Returns DocBlock for route method.
     *
     * @return DocBlockWrapper
     */
    protected function getMethodDocBlock()
    {
        if(! $this->methodDocBlock) {
            return $this->methodDocBlock = new DocBlockWrapper($this->getMethodReflection());
        }

        return $this->methodDocBlock;
    }

    /**
     * Returns DocBlock for the controller.
     *
     * @return DocBlockWrapper
     */
    protected function getControllerDocBlock()
    {
        if(! $this->controllerDockBlock) {
            return $this->controllerDockBlock = new DocBlockWrapper($this->getControllerReflection());
        }

        return $this->controllerDockBlock;
    }

    /**
     * Returns route's FormRequest reflection if exists.
     *
     * @return ReflectionClass
     */
    protected function getFormRequestClassReflection()
    {
        if(! $this->parsedFormRequest) {
            $methodParameter = $this->getMethodParameter(FormRequest::class);

            if(!$methodParameter) {
                return null;
            }

            return $this->parsedFormRequest = $methodParameter->getClass();
        }

        return $this->parsedFormRequest;
    }

    /**
     * Returns method parameter by type (single).
     *
     * @param string $filter
     *
     * @return ReflectionParameter
     */
    protected function getMethodParameter($filter = null)
    {
        $formRequestParameters = $this->getMethodParameters($filter);

        if(empty($formRequestParameters)) {
            return null;
        }

        return array_first($formRequestParameters);
    }

    /**
     * Returns route method's parameters filtered by type.
     *
     * @param string $filter A parameter type to filter.
     *
     * @return ReflectionParameter[]
     */
    protected function getMethodParameters($filter = null)
    {
        $parameters = $this->getMethodReflection()->getParameters();

        if($filter == null) {
            return $parameters;
        }

        return array_filter($parameters, function($parameter) use ($filter) {
            /** @var ReflectionParameter $parameter */
            if(! ($type = $parameter->getType())) {
                return false;
            }

            if($type->isBuiltin()) {
                return strval($type) === $filter;
            }

            return ($class = $parameter->getClass()) && $class->isSubclassOf($filter);
        });
    }

    /**
     * Returns route method's reflection.
     *
     * @return ReflectionFunctionAbstract
     */
    protected function getMethodReflection()
    {
        return $this->getControllerReflection()->getMethod($this->parseAction()[1]);
    }

    /**
     * Returns controller class reflection.
     *
     * @return ReflectionClass
     */
    protected function getControllerReflection()
    {
        return new ReflectionClass($this->parseAction()[0]);
    }
}