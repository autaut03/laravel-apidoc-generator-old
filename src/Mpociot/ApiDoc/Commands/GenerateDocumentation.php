<?php

namespace Mpociot\ApiDoc\Commands;

use Mpociot\ApiDoc\Exceptions\NoTypeSpecifiedException;
use Mpociot\ApiDoc\RouteWrapper;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Mpociot\Documentarian\Documentarian;
use Mpociot\ApiDoc\Postman\CollectionWriter;

class GenerateDocumentation extends BaseCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'api:generate
                            {--output=public/docs : The output path for the generated documentation}
                            {--routePrefix= : The route prefix to use for generation}
                            {--routes=* : The route names to use for generation}
                            {--noPostmanCollection : Disable Postman collection creation}
                            {--skipTypeChecks : Skip \'no type specified\' parameter exceptions}
                            {--M|mask=* : Route mask to check (multiple allowed)}
                            {--fullErrors : Should full exception traces be shown}
    ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate your API documentation from existing Laravel routes.';

    /**
     * Execute the console command.
     *
     * @return false|null
     */
    public function handle()
    {
        $this->addStyles();

        $allowedRoutes = $this->option('routes');
        $routePrefix = $this->option('routePrefix');

        if ($routePrefix === null && ! count($allowedRoutes)) {
            $this->error('You must provide either a route prefix or a route to generate the documentation.');

            return false;
        }

        $parsedRoutes = $this->processRoutes($allowedRoutes, $routePrefix);
        $parsedRoutes = collect($parsedRoutes)->groupBy('resource')->sort(function ($a, $b) {
            return strcmp($a->first()['resource'], $b->first()['resource']);
        });

        $this->writeMarkdown($parsedRoutes);
    }

    /**
     * @param $allowedRoutes
     * @param $routePrefix
     *
     * @return array
     */
    private function processRoutes($allowedRoutes, $routePrefix)
    {
        $parsedRoutes = [];

        foreach ($this->getRoutes() as $route) {
            $routeStr = '<red>['.implode(',', $route->getMethods()).'] '.$route->getUri().' at '.$route->getActionSafe().'</red>';

            $this->overwrite("Processing route $routeStr", 'info');

            if(
                // Does this route match route mask
                (!in_array($route->getName(), $allowedRoutes) && !str_is($routePrefix, $route->getUri())) ||
                // Is it valid
                !$route->isSupported() ||
                // Should it be skipped
                $route->isHiddenFromDocs()
            ) {
                $this->overwrite("Skipping route $routeStr", 'warn');
                continue;
            }

            try {
                $parsedRoutes[] = $route->getSummary();
                $this->overwrite("Processed route $routeStr", 'info');
            } catch (NoTypeSpecifiedException $exception) {
                $this->output->writeln('');
                $this->warn($exception->getMessage());
            } catch (\Exception $exception) {
                $this->output->writeln('');
                $exceptionStr = $this->option('fullErrors') ? $exception : $exception->getMessage();
                $this->error("Failed to process: " . $exceptionStr);
                continue;
            }
            $this->info("");
        }
        $this->info("");

        return $parsedRoutes;
    }

    /**
     * Get all routes wrapped in helper class.
     *
     * @return RouteWrapper[]
     */
    private function getRoutes()
    {
        return array_map(function($route) {
            return new RouteWrapper($route, $this->options());
        }, Route::getRoutes()->get());
    }

    /**
     * @param  Collection $parsedRoutes
     *
     * @return void
     */
    private function writeMarkdown($parsedRoutes)
    {
        $outputPath = $this->option('output');
        $targetFile = $outputPath.DIRECTORY_SEPARATOR.'source'.DIRECTORY_SEPARATOR.'index.md';
        $compareFile = $outputPath.DIRECTORY_SEPARATOR.'source'.DIRECTORY_SEPARATOR.'.compare.md';

        $infoText = view('apidoc::partials.info')
            ->with('outputPath', ltrim($outputPath, 'public/'))
            ->with('showPostmanCollectionButton', ! $this->option('noPostmanCollection'));

        $parsedRouteOutput = $parsedRoutes->map(function ($routeGroup) {
            return $routeGroup->map(function ($route) {
                $route['output'] = (string) view('apidoc::partials.route')->with('parsedRoute', $route)->render();

                return $route;
            });
        });

        $frontmatter = view('apidoc::partials.frontmatter');
        /*
         * In case the target file already exists, we should check if the documentation was modified
         * and skip the modified parts of the routes.
         */
        if (file_exists($targetFile) && file_exists($compareFile)) {
            $generatedDocumentation = file_get_contents($targetFile);
            $compareDocumentation = file_get_contents($compareFile);

            if (preg_match('/<!-- START_INFO -->(.*)<!-- END_INFO -->/is', $generatedDocumentation, $generatedInfoText)) {
                $infoText = trim($generatedInfoText[1], "\n");
            }

            if (preg_match('/---(.*)---\\s<!-- START_INFO -->/is', $generatedDocumentation, $generatedFrontmatter)) {
                $frontmatter = trim($generatedFrontmatter[1], "\n");
            }

            $parsedRouteOutput->transform(function ($routeGroup) use ($generatedDocumentation, $compareDocumentation) {
                return $routeGroup->transform(function ($route) use ($generatedDocumentation, $compareDocumentation) {
                    if (preg_match('/<!-- START_'.$route['id'].' -->(.*)<!-- END_'.$route['id'].' -->/is', $generatedDocumentation, $routeMatch)) {
                        $routeDocumentationChanged = (preg_match('/<!-- START_'.$route['id'].' -->(.*)<!-- END_'.$route['id'].' -->/is', $compareDocumentation, $compareMatch) && $compareMatch[1] !== $routeMatch[1]);
                        if ($routeDocumentationChanged === false) {
                            if ($routeDocumentationChanged) {
                                $this->warn('Discarded manual changes for route ['.implode(',', $route['methods']).'] '.$route['uri']);
                            }
                        } else {
                            $this->warn('Skipping modified route ['.implode(',', $route['methods']).'] '.$route['uri']);
                            $route['modified_output'] = $routeMatch[0];
                        }
                    }

                    return $route;
                });
            });
        }

        $documentarian = new Documentarian();

        $markdown = view('apidoc::documentarian')
            ->with('writeCompareFile', false)
            ->with('frontmatter', $frontmatter)
            ->with('infoText', $infoText)
            ->with('outputPath', $this->option('output'))
            ->with('showPostmanCollectionButton', ! $this->option('noPostmanCollection'))
            ->with('parsedRoutes', $parsedRouteOutput);

        if (! is_dir($outputPath)) {
            $documentarian->create($outputPath);
        }

        // Write output file
        file_put_contents($targetFile, $markdown);

        // Write comparable markdown file
        $compareMarkdown = view('apidoc::documentarian')
            ->with('writeCompareFile', true)
            ->with('frontmatter', $frontmatter)
            ->with('infoText', $infoText)
            ->with('outputPath', $this->option('output'))
            ->with('showPostmanCollectionButton', ! $this->option('noPostmanCollection'))
            ->with('parsedRoutes', $parsedRouteOutput);

        file_put_contents($compareFile, $compareMarkdown);

        $this->info('Wrote index.md to: '.$outputPath);

        $this->info('Generating API HTML code');

        $documentarian->generate($outputPath);

        $this->info('Wrote HTML documentation to: '.$outputPath.'/public/index.html');

        if ($this->option('noPostmanCollection') !== true) {
            $this->info('Generating Postman collection');

            file_put_contents($outputPath.DIRECTORY_SEPARATOR.'collection.json', $this->generatePostmanCollection($parsedRoutes));
        }
    }

    /**
     * Generate Postman collection JSON file.
     *
     * @param Collection $routes
     *
     * @return string
     */
    private function generatePostmanCollection(Collection $routes)
    {
        $writer = new CollectionWriter($routes);

        return $writer->getCollection();
    }
}
