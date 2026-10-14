<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\PriceList;
use App\Models\Unit;
use App\Support\BusinessException;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * ABM genérico de tablas auxiliares del catálogo.
 */
class CatalogController extends Controller
{
    public const TYPES = ['categorias', 'marcas', 'unidades', 'listas-precio'];

    private const CONFIG = [
        'categorias' => [Category::class, 'Categorías', 'products'],
        'marcas' => [Brand::class, 'Marcas', 'products'],
        'unidades' => [Unit::class, 'Unidades de medida', null],
        'listas-precio' => [PriceList::class, 'Listas de precio', null],
    ];

    public function index(string $type)
    {
        [$class, $title, $count] = self::CONFIG[$type];
        $q = $class::query()->orderBy('name');
        if ($count) {
            $q->withCount($count);
        }
        if ($type === 'categorias') {
            $q->with('parent:id,name');
        }

        return Inertia::render('Catalog/Index', [
            'type' => $type,
            'title' => $title,
            'items' => $q->get(),
            'parents' => $type === 'categorias' ? Category::whereNull('parent_id')->orderBy('name')->get(['id', 'name']) : [],
        ]);
    }

    private function rules(string $type): array
    {
        return match ($type) {
            'categorias' => ['name' => 'required|string|max:100', 'parent_id' => 'nullable|integer', 'default_markup' => 'nullable|numeric'],
            'marcas' => ['name' => 'required|string|max:100'],
            'unidades' => ['name' => 'required|string|max:50', 'abbreviation' => 'required|string|max:10', 'allows_decimals' => 'boolean'],
            'listas-precio' => ['name' => 'required|string|max:100', 'adjustment_percent' => 'required|numeric|min:-100|max:1000', 'is_default' => 'boolean', 'is_active' => 'boolean'],
        };
    }

    public function store(Request $request, string $type)
    {
        $class = self::CONFIG[$type][0];
        $data = $request->validate($this->rules($type));
        $this->checkParent($type, $data);
        $item = $class::create($data);
        $this->ensureSingleDefault($type, $item);

        return back()->with('success', 'Registro creado.');
    }

    public function update(Request $request, string $type, int $id)
    {
        $class = self::CONFIG[$type][0];
        $item = $class::findOrFail($id);
        $data = $request->validate($this->rules($type));
        $this->checkParent($type, $data, $id);
        $item->update($data);
        $this->ensureSingleDefault($type, $item);

        return back()->with('success', 'Registro actualizado.');
    }

    public function destroy(string $type, int $id)
    {
        [$class, , $count] = self::CONFIG[$type];
        $item = $class::findOrFail($id);
        if ($count && $item->{$count}()->exists()) {
            throw new BusinessException('No se puede eliminar: tiene productos asociados.');
        }
        if ($type === 'listas-precio' && $item->is_default) {
            throw new BusinessException('No se puede eliminar la lista de precios principal.');
        }
        $item->delete();

        return back()->with('success', 'Registro eliminado.');
    }

    private function checkParent(string $type, array $data, ?int $id = null): void
    {
        if ($type === 'categorias' && ! empty($data['parent_id'])) {
            Category::findOrFail($data['parent_id']);
            if ($id && (int) $data['parent_id'] === $id) {
                throw new BusinessException('Una categoría no puede ser su propia categoría padre.');
            }
        }
    }

    private function ensureSingleDefault(string $type, $item): void
    {
        if ($type === 'listas-precio' && $item->is_default) {
            PriceList::where('id', '!=', $item->id)->update(['is_default' => false]);
            $item->update(['adjustment_percent' => 0]);
        }
    }
}
