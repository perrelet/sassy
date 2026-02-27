<?php

namespace Sassy;

interface Compiler_Engine {

    public function compile (array $args) : Compile_Result;

}