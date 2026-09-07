<?php

namespace Sassy;

/**
 * What a post-processor is given besides the CSS: which asset it is working on, and somewhere to
 * report. A filter callback has neither, which is why post-processors get a typed contract.
 */
class Post_Process_Context {

    protected $asset;
    protected $diagnostics = [];

    public function __construct (Asset $asset) {

        $this->asset = $asset;

    }

    public function get_asset () {

        return $this->asset;

    }

    public function report (Diagnostic $diagnostic) {

        $this->diagnostics[] = $diagnostic;

    }

    /**
     * Report something that did not stop the build but the author should see.
     */
    public function notice ($message, array $fields = []) {

        $this->report(new Diagnostic(Diagnostic::NOTICE, $message, $fields + ['source' => 'sassy']));

    }

    public function warn ($message, array $fields = []) {

        $this->report(new Diagnostic(Diagnostic::WARNING, $message, $fields + ['source' => 'sassy']));

    }

    /**
     * @return Diagnostic[]
     */
    public function get_diagnostics () {

        return $this->diagnostics;

    }

}
