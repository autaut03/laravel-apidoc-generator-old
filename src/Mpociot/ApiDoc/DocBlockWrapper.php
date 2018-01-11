<?php

namespace Mpociot\ApiDoc;

use Illuminate\Support\Collection;
use Mpociot\Reflection\DocBlock;
use Mpociot\Reflection\DocBlock\Tag;

class DocBlockWrapper extends DocBlock
{
    /**
     * Returns doc tags by name (ignore case).
     *
     * @param string $name
     *
     * @return Collection|Tag[]
     */
    public function getDocTags($name = null)
    {
        $tags = collect($this->getTags());

        if($name == null) {
            return $tags;
        }

        return $tags->filter(function($tag) use ($name) {
            /** @var Tag $tag */
            return strtolower($tag->getName()) === strtolower($name);
        });
    }

    /**
     * Returns doc tag by name (ignore case).
     *
     * @param string $name
     *
     * @return Tag
     */
    public function getDocTag($name)
    {
        return array_first($this->getDocTags($name));
    }

    /**
     * Checks if doc contains specified tag (ignore case).
     *
     * @param string $name
     *
     * @return boolean
     */
    public function hasDocTag($name)
    {
        return $this->getDocTags($name)->isNotEmpty();
    }
}