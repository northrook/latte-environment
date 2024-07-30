<?php

declare ( strict_types = 1 );

namespace Northrook\Latte;

use LogicException;
use function array_search, count, file_exists, in_array, krsort, str_ends_with, str_starts_with;
use function Northrook\normalizePath;

/**
 * @internal
 */
final class TemplateChainLoader
{
    private bool $locked = false;

    /** @var array{string: string} */
    private array $templateDirectories = [];

    public function __construct(
        private readonly string $projectDirectory,
    ) {}


    public function add( string $path, bool | int $priority = false ) : void {

        if ( $this->locked ) {
            throw new LogicException(
                'Template directory cannot be added, the Loader is locked. 
                The Loader is locked automatically when any template is first read.',
            );
        }

        $priority = ( $priority === true )
            ? PHP_INT_MAX
            : $priority ?? count( $this->templateDirectories );

        $path = normalizePath( $path );

        if ( in_array( $path, $this->templateDirectories ) ) {
            unset( $this->templateDirectories[ array_search( $path, $this->templateDirectories ) ] );
        }

        $this->templateDirectories[ $priority ] = $path;
    }

    /**
     * @param string  $template
     *
     * @return string
     */
    public function load( string $template ) : string {

        if ( !$this->locked ) {
            krsort( $this->templateDirectories, SORT_DESC );
            $this->locked = true;
        }

        if ( !str_ends_with( $template, '.latte' ) ) {
            return $template;
        }

        $template = normalizePath( $template );

        if ( str_starts_with( $template, $this->projectDirectory ) && file_exists( $template ) ) {
            return $template;
        }

        foreach ( $this->templateDirectories as $directory ) {

            if ( str_starts_with( $template, $directory ) && file_exists( $directory ) ) {
                return $template;
            }


            $path = $directory . DIRECTORY_SEPARATOR . $template;

            if ( file_exists( $path ) ) {
                return $path;
            }
        }

        return $template;
    }
}