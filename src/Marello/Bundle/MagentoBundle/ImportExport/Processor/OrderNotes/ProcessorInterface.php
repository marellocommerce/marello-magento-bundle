<?php

namespace Marello\Bundle\MagentoBundle\ImportExport\Processor\OrderNotes;

interface ProcessorInterface
{
    /**
     * @param Context $context
     */
    public function process(Context $context);
}
