<?php
namespace Dust\Filter;

class JsonEncode implements Filter
{
    public function apply($item) {
        return json_encode($item, JSON_THROW_ON_ERROR);
    }

}