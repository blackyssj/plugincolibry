<?php


namespace ColibriSync\Models;

class ProductModel {
    public $codigoUnico;
    public $sku;
    public $titulo;
    public $tipoProducto;
    public $descripcionCorta;
    public $categoriaNivel1;
    public $categoriaNivel2;
    public $categoriaNivel3;
    public $temporada;
    public $nombreAtributoColor;
    public $valorAtributoColor;
    public $nombreAtributoTalla;
    public $valorAtributoTalla;
    public $precioNormal;
    public $stock;
    public $imagenPrincipal;
    public $otrasImagenes;

    public function __construct(array $data) {
        $this->codigoUnico = $data['CODIGO_UNICO'] ?? null;
        $this->sku = $data['CODIGO_SKU'] ?? null;
        $this->titulo = $data['TITULO'] ?? null;
        $this->tipoProducto = $data['TIPO_DE_PRODUCTO'] ?? 'simple';
        $this->descripcionCorta = $data['DESCRIPCION_CORTA'] ?? null;
        $this->categoriaNivel1 = $data['CATEGORIA_NIVEL_1'] ?? null;
        $this->categoriaNivel2 = $data['CATEGORIA_NIVEL_2'] ?? null;
        $this->categoriaNivel3 = $data['CATEGORIA_NIVEL_3'] ?? null;
        $this->temporada = $data['TEMPORADA'] ?? null;
        $this->nombreAtributoColor = $data['NOMBRE_DE_ATRIBUTO_COLOR'] ?? null;
        $this->valorAtributoColor = $data['VALOR_DE_ATRIBUTO_COLOR'] ?? null;
        $this->nombreAtributoTalla = $data['NOMBRE_DE_ATRIBUTO_TALLA'] ?? null;
        $this->valorAtributoTalla = $data['VALOR_DE_ATRIBUTO_TALLA'] ?? null;
        $this->precioNormal = $data['PRECIO_NORMAL'] ?? null;
        $this->stock = $data['STOCK'] ?? 0;
        $this->imagenPrincipal = $data['IMAGEN_PRINCIPAL'] ?? null;
        $this->otrasImagenes = isset($data['OTRAS_IMAGENES']) ? explode('|', rtrim($data['OTRAS_IMAGENES'], '|')) : [];
    }
}
