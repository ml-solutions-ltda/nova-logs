<?php

namespace MlSolutions\NovaLogViewer;

use Illuminate\Http\Request;
use Laravel\Nova\Menu\MenuGroup;
use Laravel\Nova\Menu\MenuItem;
use Laravel\Nova\Menu\MenuSection;
use Laravel\Nova\Nova;
use Laravel\Nova\Tool as BaseTool;

class Tool extends BaseTool
{
    /**
     * Perform any tasks that need to happen when the tool is booted.
     *
     * @return void
     */
    public function boot()
    {
        $script = __DIR__.'/../dist/js/tool.js';
        $style = __DIR__.'/../dist/css/tool.css';

        if (is_file($script)) {
            Nova::script('nova-logs-'.substr(hash_file('sha256', $script), 0, 12), $script);
        }

        if (is_file($style)) {
            Nova::style('nova-logs-'.substr(hash_file('sha256', $style), 0, 12), $style);
        }
    }

    /**
     * Build the menu that renders the navigation links for the tool.
     *
     * @param \Illuminate\Http\Request $request
     * @return mixed
     */
    public function menu(Request $request)
    {
        return MenuSection::make('Log Viewer', [
            MenuItem::link('Dashboard', '/nova-logs/dashboard'),
            MenuItem::link('Logs', '/nova-logs/list'),
        ])
            ->collapsable()
            ->icon('document-text');
    }
}
