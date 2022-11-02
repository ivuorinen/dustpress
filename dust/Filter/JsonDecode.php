<?php
namespace Dust\Filter;

class JsonDecode implements Filter
{
    public function apply($item) {
        return json_decode($item, null, 512, JSON_THROW_ON_ERROR);
    }

}