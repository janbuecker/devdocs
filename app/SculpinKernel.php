<?php
use Bcremer\Sculpin\Bundle\CommonMarkBundle\SculpinCommonMarkBundle;
use Bcremer\Sculpin\Bundle\LessBundle\SculpinLessBundle;
use Mavimo\Sculpin\Bundle\RedirectBundle\SculpinRedirectBundle;
use Sculpin\Bundle\SculpinBundle\HttpKernel\AbstractKernel;
use Shopware\Devdocs\VersioningMenuBundle\SculpinVersioningMenuBundle;

class SculpinKernel extends AbstractKernel
{
    protected function getAdditionalSculpinBundles()
    {
        return [
            SculpinRedirectBundle::class,
            SculpinLessBundle::class,
            SculpinCommonMarkBundle::class,
            SculpinVersioningMenuBundle::class
        ];
    }
}
