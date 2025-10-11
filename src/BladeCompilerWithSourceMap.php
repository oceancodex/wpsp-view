<?php

namespace WPSPCORE\View;

use Illuminate\View\Compilers\BladeCompiler;

class BladeCompilerWithSourceMap extends BladeCompiler {

	public function compile($path = null) {
		parent::compile($path);

		if ($path && $this->files->exists($compiledPath = $this->getCompiledPath($path))) {

			$sourcePath = $path;

			// Đọc SOURCE inline ở đầu file nguồn nếu có
			if ($this->files->exists($path)) {
				$head = $this->files->get($path);
				if ($head !== '') {
					if (preg_match('/SOURCE:\s*(.+?)\s*\*\//u', $head, $m) && !empty($m[1])) {
						$maybe = trim($m[1]);
						if ($maybe !== '') {
							$sourcePath = $maybe;
						}
					}
				}
			}

			$contents = $this->files->get($compiledPath);
			$header   = "<?php /** SOURCE: {$sourcePath} */ ?>\n";

			if (strpos($contents, $header) !== 0) {
				$contents = $header . $contents;
				$this->files->put($compiledPath, $contents);
			}
		}
	}

}