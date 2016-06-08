<?php
/**
 * @package   Gantry5
 * @author    RocketTheme http://www.rockettheme.com
 * @copyright Copyright (C) 2007 - 2016 RocketTheme, LLC
 * @license   Dual License: MIT or GNU/GPLv2 and later
 *
 * http://opensource.org/licenses/MIT
 * http://www.gnu.org/licenses/gpl-2.0.html
 *
 * Gantry Framework code that extends GPL code is considered GNU/GPLv2 and later
 */

namespace Gantry\Admin\Controller\Html\Configurations;

use Gantry\Component\Config\BlueprintsForm;
use Gantry\Component\Config\Config;
use Gantry\Component\Controller\HtmlController;
use Gantry\Component\File\CompiledYamlFile;
use Gantry\Component\Layout\Layout as LayoutObject;
use Gantry\Component\Layout\LayoutReader;
use Gantry\Component\Request\Request;
use Gantry\Component\Response\JsonResponse;
use Gantry\Framework\Outlines;
use RocketTheme\Toolbox\Blueprints\Blueprints;
use RocketTheme\Toolbox\Event\Event;
use RocketTheme\Toolbox\File\JsonFile;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;

class Layout extends HtmlController
{
    protected $httpVerbs = [
        'GET'    => [
            '/'         => 'index',
            '/create'   => 'create',
            '/create/*' => 'create',
            '/*'        => 'undefined',
            '/switch'   => 'listSwitches',
            '/switch/*' => 'switchLayout',
            '/preset'   => 'undefined',
            '/preset/*' => 'preset'
        ],
        'POST'   => [
            '/'                     => 'save',
            '/*'                    => 'undefined',
            '/*/*'                  => 'particle',
            '/switch'               => 'undefined',
            '/switch/*'             => 'switchLayout',
            '/preset'               => 'undefined',
            '/preset/*'             => 'preset',
            '/particles'            => 'undefined',
            '/particles/*'          => 'undefined',
            '/particles/*/validate' => 'validate'
        ],
        'PUT'    => [
            '/*' => 'replace'
        ],
        'PATCH'  => [
            '/*' => 'update'
        ],
        'DELETE' => [
            '/*' => 'destroy'
        ]
    ];

    public function create($id = null)
    {
        if (!$id) {
            // TODO: we might want to display list of options here
            throw new \RuntimeException('Not Implemented', 404);
        }

        $layout = $this->getLayout("presets/{$id}");
        if (!$layout) {
            throw new \RuntimeException('Preset not found', 404);
        }
        $this->params['page_id'] = $id;
        $this->params['layout'] = $layout->prepareWidths()->toArray();

        return $this->container['admin.theme']->render('@gantry-admin/pages/configurations/layouts/create.html.twig', $this->params);
    }

    public function index()
    {
        $id = $this->params['configuration'];
        $layout = $this->getLayout($id);
        if (!$layout) {
            throw new \RuntimeException('Layout not found', 404);
        }

        $groups = [
            'Positions' => ['position' => [], 'spacer' => [], 'system' => []],
            'Particles' => ['particle' => []]
        ];

        $particles = [
            'position'    => [],
            'spacer'      => [],
            'system' => [],
            'particle' => []
        ];

        $particles = array_replace($particles, $this->getParticles());
        foreach ($particles as &$group) {
            asort($group);
        }

        foreach ($groups as $section => $children) {
            foreach ($children as $key => $child) {
                $groups[$section][$key] = $particles[$key];
            }
        }

        $this->params['page_id'] = $id;
        $this->params['layout'] = $layout->prepareWidths()->toArray();
        $this->params['preset'] = $layout->preset;
        $this->params['preset_title'] = ucwords(trim(str_replace('_', ' ', $layout->preset['name'])));
        $this->params['id'] = ucwords(str_replace('_', ' ', ltrim($id, '_')));
        $this->params['particles'] = $groups;
        $this->params['switcher_url'] = str_replace('.', '/', "configurations.{$id}.layout.switch");

        return $this->container['admin.theme']->render('@gantry-admin/pages/configurations/layouts/edit.html.twig', $this->params);
    }

    public function save()
    {
        $layout = $this->request->post->get('layout');
        if ($layout && $layout[0] !== '{' && $layout[0] !== '[') {
            $layout = urldecode((string)base64_decode($layout));
        }
        $layout = json_decode($layout);

        if (!isset($layout)) {
            throw new \RuntimeException('Error while saving layout: Structure missing', 400);
        }

        $outline = $this->params['configuration'];
        $preset = $this->request->post->getJsonArray('preset');

        // Create layout from the data.
        $layout = new LayoutObject($outline, $layout, $preset);

        /** @var Outlines $outlines */
        $outlines = $this->container['configurations'];

        // Update layouts from all inheriting outlines.
        $elements = $layout->sections() + $layout->particles(false);
        foreach ($outlines->getInheritingOutlines($outline) as $inheritedId => $inheritedName) {
            LayoutObject::instance($inheritedId)->updateInheritance($outline, $outline, $elements)->save()->saveIndex();
        }

        // Save layout and its index.
        $layout->save()->saveIndex();

        // Fire save event.
        $event = new Event;
        $event->gantry = $this->container;
        $event->theme = $this->container['theme'];
        $event->controller = $this;
        $event->layout = $layout;
        $this->container->fireEvent('admin.layout.save', $event);
    }

    public function particle($type, $id)
    {
        if ($type == 'atom') { return ''; }

        $outline = $this->params['configuration'];
        $layout = $this->getLayout($outline);
        if (!$layout) {
            throw new \RuntimeException('Layout not found', 404);
        }

        $item = $layout->find($id);
        $item->type    = $this->request->post['type'] ?: $type;
        $item->subtype = $this->request->post['subtype'] ?: $type;
        $item->title   = $this->request->post['title'] ?: ucfirst($type);
        if (!isset($item->attributes)) {
            $item->attributes = new \stdClass;
        }
        if (!isset($item->inherit)) {
            $item->inherit = new \stdClass;
        }

        $block = $this->request->post->getArray('block');
        if (!empty($block)) {
            $item->block = (object) $block;
        }

        $attributes = $this->request->post->getArray('options');
        $inherit = $this->request->post->getArray('inherit');

        $particle = !$layout->isLayoutType($type);
        if (!$particle) {
            $name = $type;
            $section = ($type == 'section');
            $hasBlock = $section && !empty($block);
            $prefix = "particles.{$type}";
            $defaults = [];
            $attributes += (array) $item->attributes + $defaults;
            $file = CompiledYamlFile::instance("gantry-admin://blueprints/layout/{$type}.yaml");
            $blueprints = new BlueprintsForm($file->content());
            $file->free();
        } else {
            $name = $item->subtype;
            $hasBlock = true;
            $prefix = "particles.{$name}";
            $defaults = (array) $this->container['config']->get($prefix);
            $attributes += $defaults;
            $blueprints = new BlueprintsForm($this->container['particles']->get($name));
            $blueprints->set('form.fields._inherit', ['type' => 'gantry.inherit']);
        }

        if ($hasBlock) {
            $file = CompiledYamlFile::instance("gantry-admin://blueprints/layout/block.yaml");
            $blockBlueprints = new BlueprintsForm($file->content());
            $file->free();
        } else {
            $blockBlueprints = null;
        }

        $file = CompiledYamlFile::instance("gantry-admin://blueprints/layout/inheritance/{$type}.yaml");
        if ($file->exists()) {
            $inheritType = $particle ? 'particle' : 'section';

            /** @var Outlines $outlines */
            $outlines = $this->container['configurations'];

            if ($outline !== 'default') {
                $funcName = 'getOutlinesWith' . ucfirst($inheritType);
                $list = (array)$outlines->{$funcName}($particle ? $item->subtype : $item->id, false);
                unset($list[$outline]);
            } else {
                $list = [];
            }

            if (!empty($inherit['outline']) || (!($inheriting = $outlines->getInheritingOutlines($outline, $id)) && $list)) {
                $inheritance = new BlueprintsForm($file->content());
                $file->free();

                $inheritance->set('form.fields.outline.filter', array_keys($list));
                if (!$hasBlock) {
                    $inheritance->undef('form.fields.include.options.block');
                }

                if ($particle) {
                    $inheritance->set('form.fields.particle.particle', $name);
                }

            } elseif (!empty($inheriting)) {
                // Already inherited by other outlines.
                $file = CompiledYamlFile::instance("gantry-admin://blueprints/layout/inheritance/messages/inherited.yaml");
                $inheritance = new BlueprintsForm($file->content());
                $file->free();
                $inheritance->set(
                    'form.fields._note.content',
                    sprintf($inheritance->get('form.fields._note.content'), $inheritType, ' <ul><li>' . implode('</li>, <li>', $inheriting) . '</li></ul>')
                );

            } elseif ($outline === 'default') {
                // Base outline.
                $file = CompiledYamlFile::instance("gantry-admin://blueprints/layout/inheritance/messages/default.yaml");
                $inheritance = new BlueprintsForm($file->content());
                $file->free();

            } else {
                // Nothing to inherit from.
                $file = CompiledYamlFile::instance("gantry-admin://blueprints/layout/inheritance/messages/empty.yaml");
                $inheritance = new BlueprintsForm($file->content());
                $file->free();
            }
        }

        // TODO: Use blueprints to merge configuration.
        $item->attributes = (object) $attributes;
        $item->inherit = (object) $inherit;

        $this->params['id'] = $name;
        $this->params += [
            'extra'         => $blockBlueprints,
            'inherit'       => !empty($inherit['outline']) ? $inherit['outline'] : null,
            'inheritance'   => isset($inheritance) ? $inheritance : null,
            'item'          => $item,
            'data'          => ['particles' => [$name => $item->attributes]],
            'defaults'      => ['particles' => [$name => $defaults]],
            'prefix'        => "particles.{$name}.",
            'particle'      => $blueprints,
            'parent'        => 'settings',
            'route'         => "configurations.{$outline}.settings",
            'action'        => str_replace('.', '/', 'configurations.' . $outline . '.layout.' . $prefix . '.validate'),
            'skip'          => ['enabled'],
            'editable'      => $particle,
            'overrideable'  => $particle,
        ];

        if ($particle) {
            $result = $this->container['admin.theme']->render('@gantry-admin/pages/configurations/layouts/particle.html.twig',
                $this->params);
        } else {
            $typeLayout = $type == 'container' ? 'container' : 'section';
            $result = $this->container['admin.theme']->render('@gantry-admin/pages/configurations/layouts/' . $typeLayout . '.html.twig',
                $this->params);
        }

        return $result;
    }

    public function listSwitches()
    {
        $this->params['presets'] = LayoutObject::presets();
        $result = $this->container['admin.theme']->render('@gantry-admin/layouts/switcher.html.twig', $this->params);

        return new JsonResponse(['html' => $result]);
    }

    public function switchLayout($id)
    {
        // Validate only exists for JSON.
        if (empty($this->params['ajax'])) {
            $this->undefined();
        }

        $layout = $this->getLayout($id);
        if (!$layout->toArray()) {
            // Layout hasn't been defined, return default layout instead.
            $layout = $this->getLayout('default');
        }

        $input = $this->request->post->getJson('layout');
        $deleted = isset($input) ? $layout->clearSections()->copySections($input): [];
        if (!$input && $this->request->post['inherit'] === '1') {
            $layout->inheritAll();
        }

        $message = $deleted
            ? $this->container['admin.theme']->render('@gantry-admin/ajax/particles-loss.html.twig', ['particles' => $deleted])
            : null;

        return new JsonResponse([
            'title' => ucwords(trim(str_replace('_', ' ', $layout->preset['name']))),
            'preset' => json_encode($layout->preset),
            'data' => $layout->prepareWidths()->toJson(),
            'deleted' => $deleted,
            'message' => $message
        ]);
    }

    public function preset($id)
    {
        // Validate only exists for JSON.
        if (empty($this->params['ajax'])) {
            $this->undefined();
        }

        $preset = LayoutObject::preset($id);
        if (!$preset) {
            throw new \RuntimeException('Preset not found', 404);
        }

        $layout = new LayoutObject($id, $preset);

        $input = $this->request->post->getJson('layout');
        $deleted = isset($input) ? $layout->clearSections()->copySections($input): [];
        $message = $deleted
            ? $this->container['admin.theme']->render('@gantry-admin/ajax/particles-loss.html.twig', ['particles' => $deleted])
            : null;

        return new JsonResponse([
            'title' => ucwords(trim(str_replace('_', ' ', $id))),
            'preset' => json_encode($layout->preset),
            'data' => $layout->prepareWidths()->toJson(),
            'deleted' => $deleted,
            'message' => $message
        ]);
    }

    public function validate($particle)
    {
        // Validate only exists for JSON.
        if (empty($this->params['ajax'])) {
            $this->undefined();
        }

        // Load particle blueprints and default settings.
        $validator = new Blueprints();

        $name = $particle;
        if (in_array($particle, ['wrapper', 'section', 'container', 'grid', 'offcanvas'])) {
            $type = $particle;
            $particle = null;
            $file = CompiledYamlFile::instance("gantry-admin://blueprints/layout/{$type}.yaml");
            $validator->embed('options', $file->content());
            $file->free();
        } else {
            $type = in_array($particle, ['spacer', 'system', 'position']) ? $particle :  'particle';
            $validator->embed('options', $this->container['particles']->get($particle));
        }

        // Create configuration from the defaults.
        $data = new Config(
            [
                'type'    => $type,
            ],
            function () use ($validator) {
                return $validator;
            }
        );

        // Join POST data.
        $data->join('options', $this->request->post->getArray("particles." . $name));
        if ($particle) {
            $data->set('options.enabled', (int) $data->get('options.enabled', 1));
        }

        if ($particle) {
            if ($type != $particle) {
                $data->set('subtype', $particle);
            }

            $data->join('title', $this->request->post['title'] ?: ucfirst($particle));
        }

        $block = $this->request->post->getArray('block');
        if ($block) {
            // TODO: remove empty items in some other way:
            foreach ($block as $key => $param) {
                if ($param === '') {
                    unset($block[$key]);
                    continue;
                }
                if ($key == 'size') {
                    $param = round($param, 4);
                    if ($param < 5) {
                        $param = 5;
                    } elseif ($param > 100) {
                        $param = 100;
                    }
                    $block[$key] = $param;
                }
            }

            $data->join('block', $block);
        }

        $inherit = $this->request->post->getArray('inherit');
        $clone = $inherit['mode'] === 'clone';
        $inherit['include'] = !empty($inherit['include']) ? explode(',', $inherit['include']) : [];
        if (!$clone && !empty($inherit['outline']) && count($inherit['include'])) {
            // Clean up inherit and add it to the data.
            if (!$block) {
                $inherit['include'] = array_values(array_diff($inherit['include'], ['block']));
            }

            $data->join('inherit', $inherit);
        }

        // Optionally send children of the object.
        if (in_array('children', $inherit['include'])) {
            $layout = LayoutObject::instance($inherit['outline'] ?: $this->params['configuration']);
            if ($clone) {
                $item = $layout->find($inherit['section']);
            } else {
                $item = $layout->inheritAll()->find($inherit['section']);
            }
            $data->join('children', $item->children);
        }

        // TODO: validate

        return new JsonResponse(['data' => $data->toArray()]);
    }

    /**
     * @param string $name
     * @return LayoutObject
     */
    protected function getLayout($name)
    {
        return LayoutObject::instance($name);
    }

    protected function getParticles($onlyEnabled = false)
    {
        $config = $this->container['config'];

        $particles = $this->container['particles']->all();

        $list = [];
        foreach ($particles as $name => $particle) {
            $type = isset($particle['type']) ? $particle['type'] : 'particle';
            $particleName = isset($particle['name']) ? $particle['name'] : $name;
            $particleIcon = isset($particle['icon']) ? $particle['icon'] : null;

            if (!$onlyEnabled || $config->get("particles.{$name}.enabled", true)) {
                $list[$type][$name] = ['name' => $particleName, 'icon' => $particleIcon];
            }
        }

        return $list;
    }
}
