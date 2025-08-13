<?php
// Table extension, https://github.com/schulle4u/yellow-table

class YellowTable {
    const VERSION = "0.9.3";
    public $yellow;         //access to API
    
    // Handle initialisation
    public function onLoad($yellow) {
        $this->yellow = $yellow;
        $this->yellow->system->setDefault("tableDirectory", "media/downloads/");
        $this->yellow->system->setDefault("tableDelimiter", "auto");
        $this->yellow->system->setDefault("tableFirstRowHeader", "1");
        $this->yellow->system->setDefault("tableFunctions", "1");
        $this->yellow->system->setDefault("tableRowsPerPage", "0");
    }

    // custom separator
    public function getGalleryNames($text, $optional = "-", $sizeMin = 9) {
        $text = preg_replace("/\s+/s", " ", trim($text));
        $text = preg_replace('/^[^"]*"/', '"', $text);
        $tokens = str_getcsv($text, ",", "\"", "");
        foreach ($tokens as $key=>$value) {
            if (is_null($value) || $value==$optional) $tokens[$key] = "";
        }
        return array_pad($tokens, $sizeMin, "");
    }
    function galleryId($client_id) {
        $hash = $this->murmur3_hash($client_id) % (36**6); // Ensure it's within a 5-char range
        return abs($hash % 100000);
    }

    function murmur3_hash($key, $seed = 0) {
        $key = array_values(unpack('C*', $key)); 
        $len = count($key);
        $c1 = 0xcc9e2d51;
        $c2 = 0x1b873593;
        $h1 = $seed;
        
        for ($i = 0; $i < $len; $i++) {
            $k1 = $key[$i];
            $k1 *= $c1;
            $k1 = ($k1 << 15) | ($k1 >> 17); // ROTL 15
            $k1 *= $c2;

            $h1 ^= $k1;
            $h1 = ($h1 << 13) | ($h1 >> 19); // ROTL 13
            $h1 = $h1 * 5 + 0xe6546b64;
        }

        $h1 ^= $len;
        $h1 ^= ($h1 >> 16);
        $h1 *= 0x85ebca6b;
        $h1 ^= ($h1 >> 13);
        $h1 *= 0xc2b2ae35;
        $h1 ^= ($h1 >> 16);

        return $h1;
    }
    
    // Handle page content element
    public function onParseContentElement($page, $name, $text, $attributes, $type) {
        $output = null;
        if ($name=="table" && ($type=="block" || $type=="inline")) {
            list($fileName, $rowsPerPage, $caption, $class) = $this->yellow->toolbox->getTextArguments($text);
            $fileName = $this->yellow->lookup->normalisePath($this->yellow->system->get("tableDirectory").$fileName);
            $fileData = $this->yellow->toolbox->readFile($fileName);
            if (is_string_empty($rowsPerPage)) $rowsPerPage = $this->yellow->system->get("tableRowsPerPage");
            if (!is_string_empty($fileData)) {
                $output = "<div class=\"".htmlspecialchars($name)."-container\" style=\"overflow-x:auto;\">\n";
                $output .= $this->getTableHtml($fileData, $rowsPerPage, $caption, $class);
                $output .= "</div>\n";
            } else {
                $this->yellow->page->error(500, "Table '$fileName' does not exist!");
            }
        }
        if ($name=="table" && $type=="code") {
            $htmlAttributes = $this->yellow->lookup->getHtmlAttributes(".table $attributes");
            if (!is_string_empty($text)) {
                $output = "<div".$htmlAttributes;
                $output .= " style=\"overflow-x:auto;\">\n";
                $output .= $this->getTableHtml($text, "", "", "");
                $output .= "</div>\n";
            }
        }

        if ($name=="faq" && ($type=="block" || $type=="inline")) {
            function removeDashesAndUnderscores($string) {
                return ucfirst(preg_replace_callback('/[-_](.)/', function ($matches) {
                    return strtoupper($matches[1]);
                }, $string));
            }
            list($fileName, $category, $limit, $random) = $this->yellow->toolbox->getTextArguments($text);
            $fileName = $this->yellow->lookup->normalisePath($this->yellow->system->get("tableDirectory").$fileName);
            $fileData = $this->yellow->toolbox->readFile($fileName);
            if (is_string_empty($limit)) $limit = 99;
            if (is_string_empty($random)) {
                $random = false;
            } else {
                $random = true;
            }
            if (!is_string_empty($fileData)) {
                $accordionId = removeDashesAndUnderscores(htmlspecialchars($category));
                $output = "<div class=\"accordion ".htmlspecialchars($name)."-container\" id=\"faq".$accordionId."\">\n";
                $output .= $this->getFaqHtml($fileData, $category, $limit, $random, $page->get("language"), $accordionId);
                $output .= "</div>\n";
            } else {
                $this->yellow->page->error(500, "FAQ Table '$fileName' does not exist!");
            }
        }

        if ($name=="gallery_section" && ($type=="block" || $type=="inline")) {
            list($galleries_folder) = $this->yellow->toolbox->getTextArguments($text);
            $galleryTitlesArr = $this->getGalleryNames($text);
            //START inside site-sectio and container-fluid DIV's (div><div>)
            $output .= '
            <div class="row">';
            $output .= "\n";

            $pathInstall = $this->yellow->system->get("coreServerInstallDirectory");
            $pathImages = $this->yellow->lookup->findMediaDirectory("coreImageLocation");
            $galleries_folder = $pathInstall.$pathImages.$galleries_folder;
            $dirs = array_slice($this->yellow->extension->get("image")->getSubDirectories($galleries_folder),1);
            $i = count($dirs)-1;
            $j = 1;
            // implement dynamic bootstrap grid calculation depending on odd/even and galleries numbers in folder VS 2-3 / 4-6+ galleries
            $paths = array();
            $dirs = array_map(function($path) use ($pathImages) {
                $substring = strstr($path, $pathImages);
                return $substring !== false ? $substring : $path;
            }, $dirs);
            $dirs = array_filter($dirs, function($path) {
                if (preg_match('/(exclude|-excl)/i', basename($path))) {
                    return false;
                }
                $files = $this->yellow->toolbox->getDirectoryEntries(
                    $path,
                    "/([a-z\-_0-9\/\:\.]*\.(jpg))/i",
                    true,
                    false
                );
                return count($files) > 1;
            });
            foreach ($dirs as $path) {
                $id = $this->galleryId($path);
                $paths[] = $path;
                $gallery_title = $galleryTitlesArr[$j-1];
                $imgs_count = count($this->yellow->toolbox->getDirectoryEntries($path, "/([a-z\-_0-9\/\:\.]*\.(jpg))/i", true, false))-1;
                // $n=$i-($i-$j)+$id;
                $output .= '
                <div class="col-12 col-sm-4" id="lightgallery-' . $id . '">
                    <div class="image-wrap-2">
                        <div class="image-info">
                            <span class="btn btn-outline-white py-2 px-4">'. $this->yellow->language->getTextHtml("ViewGallery") . '(' . $imgs_count . ')</span>';
                if ($gallery_title) {
                    $output .= '
                            <h2 class="mb-0 mt-4 h4">' . ucfirst($gallery_title) . '</h2>';
                }
                $output .= '
                        </div>
                        <img src="' . "/$path/kaas.jpg" . '" alt="' . $gallery_title . '" title="' . $this->yellow->page->getHtml("MainTitle") . ": " . $gallery_title . '" class="img-fluid original" loading="lazy">
                    </div>
                </div>';
                $j++;
            }
            $output .= '
            </div>';
            $output .= "\n";
            //END inside site-sectio and container-fluid DIV's (</div></div>)

            //Structured imagesObject data
            $baseUrl = $this->yellow->toolbox->detectServerUrl();
            $creatorName = "Tatjana Mihhailova";
            

            $imagesStructured = [];

            foreach ($paths as $path) {
                $images = $this->yellow->toolbox->getDirectoryEntries($path, "/([a-z\-_0-9\/\:\.]*\.(jpg))/i", true, false);

                $images = array_filter($images, function($image) {
                    return strpos($image, 'kaas.jpg') === false;
                });
                
                foreach ($images as $image) {
                    $iptc = [];

                    // Get IPTC metadata
                    $imageData = @getimagesize($image, $info);
                    if (isset($info['APP13'])) {
                        $iptcRaw = iptcparse($info['APP13']);
                        if ($iptcRaw !== false) {
                            // Mapping IPTC codes to readable keys
                            $iptc['license'] = $iptcRaw["2#086"][0] ?? null;             // IPTC "Copyright" field
                            $iptc['acquireLicensePage'] = $iptcRaw["2#105"][0] ?? null;  // IPTC "Headline" or custom field
                            $iptc['creditText'] = $iptcRaw["2#110"][0] ?? null;          // IPTC "Credit"
                            $iptc['copyrightNotice'] = $iptcRaw["2#116"][0] ?? null;     // IPTC "Copyright Notice"
                            $iptc['keywords'] = isset($iptcRaw["2#025"]) ? implode(", ", $iptcRaw["2#025"]) : ""; // IPTC "Keywords"
                        }
                    }

                    $structuredImage = [
                        "@context" => "https://schema.org/",
                        "@type" => "ImageObject",
                        "@id" => rtrim($baseUrl, '/') . '/' . ltrim($image, '/'),
                        "url" => $this->yellow->page->getUrl(),
                        "contentUrl" => rtrim($baseUrl, '/') . '/' . ltrim($image, '/'),
                        "license" => $iptc['license'] ?? rtrim($baseUrl, '/'),
                        "acquireLicensePage" => $iptc['acquireLicensePage'] ?? rtrim($baseUrl, '/'),
                        "creditText" => $iptc['creditText'] ?? "Labrador PhotoLab",
                        "creator" => [
                            "@type" => "Person",
                            "name" => $creatorName,
                        ],
                        "copyrightNotice" => $iptc['copyrightNotice'] ?? $creatorName,
                        "width" => $imageData[0],
                        "height" => $imageData[1],
                        "inLanguage" => $this->yellow->page->get("language"), // images metadata language
                        "keywords" => $iptc['keywords'] ?? "", // ToDo: Add more keywords, e.g. "family, portrait, autumn, forest"
                    //     "name" => pathinfo($image, PATHINFO_FILENAME), // ToDo: Short photo title, e.g. "Family Portrait in Autumn Forest"
                    //     "description" => $iptc['creditText'] ?? "Image from Labrador PhotoLab", //ToDo: Full contextual explanation - Include who, what, where, when, mood, and style
                        // "subjectOf" => [
                        //     "@type" => "Service",
                        //     "serviceType" => "Photography", // ToDo: More specific service type
                        //     "name" => "Newborn baby in a blanket", // same as "name" above
                        //     "url" => "pricelisturl#newborn" // link to pricelist with #hash to the service pricelistUrl + serviceType-related section's name
                        // ]
                    ];

                    $imagesStructured[] = $structuredImage;
                }
            }

            // Output JSON-LD
            $output .= '<script type="application/ld+json">' . PHP_EOL;
            $output .= json_encode($imagesStructured, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
            $output .= '</script>';

            //END of Structured imagesObject data


            $output .= '<script>';

            $i=1;
            foreach ($paths as $path) {
                $id = $this->galleryId($path);

                $images = $this->yellow->toolbox->getDirectoryEntries($path, "/([a-z\-_0-9\/\:\.]*\.(jpg))/i", true, false);
                $output .= "
                $('#lightgallery-$id').on('click', function() {
                    $(this).lightGallery({
                    galleryId: $id,
                    rotate: false,
                    animateThumb: true,
                    showThumbByDefault: false,
                    autoplayControls: false,
                    download: false,
                    actualSize: false,
                    zoom: true,
                    share: true,
                    googlePlus: false,
                    hideBarsDelay: 1000,
                    pause: 2600,
                    dynamic: true,
                    dynamicEl: [";                    
                    $images = array_filter($images, function($image) {
                        return strpos($image, 'kaas.jpg') === false;
                    });
                    $j = 1;
                    foreach ($images as $image) {

                        getimagesize($image, $info);
                        $header = ""; $description = "";
                        if (isset($info["APP13"])) {
                            $iptc = iptcparse($info["APP13"]);
                            if (isset($iptc["2#105"][0])) {
                                $header = "<h4>".$iptc["2#105"][0]."</h4>";
                            }
                            if (isset($iptc["2#120"][0])) {
                                $description = "<p>".$iptc["2#120"][0]."</p>";
                            }
                        }
                        
                        $alt = pathinfo($image, PATHINFO_FILENAME);
                        $alt = preg_split("/[\\s\\-|_]+/u", $alt);
                        unset($alt[0]);
                        $alt = implode(" ",$alt);
                        $alt = ucfirst(str_replace("-"," ", $alt));
                        $date_taken = DateTime::createFromFormat('Ymd', substr($iptc["2#055"][0], -8));
                        if ($date_taken) {
                            $date_taken = $date_taken->format('m.Y');
                            if ($alt) {
                                $alt .= " <i>| ". $this->yellow->language->getTextHtml("GalleryPhotoTakenOn") . " ";
                            } else {
                                $alt .= "<i>". $this->yellow->language->getTextHtml("GalleryPhotoTakenOn") . " ";
                            }
                            $alt .= $date_taken. "</i>";
                        }
                        $alt = "<h4>" . $alt . "</h4>";
                        
                        if ($this->yellow->extension->isExisting("image")) {
                            $fileName = $image;
                            list($src, $width, $height) = $this->yellow->extension->get("image")->getImageInformation($fileName, "100", "80");
                        }

                        $output .= "\n";
                        $output .= '
                        {';
                        $output .= "'src': '/$image',";
                        $output .= "'thumb': '$src'";
                        if ($header OR $description) {
                            $output .= ",";
                            $output .= "'subHtml': '$header$description'";
                        } elseif ($alt) {
                            $output .= ",";
                            $output .= "'subHtml': '$alt'";
                        }
                        $output .= '
                        }';
                        if ($j !== count($images)) {
                            $output .= ",";
                        }
                        $j++;
                    }
                    $output .= "\n";
                    $output .= '
                            ]
                        });
                    });';
                $i++;
            }
            $output .= '</script>';
        }

        return $output;
    }
    
    // Handle page extra data
    public function onParsePageExtra($page, $name) {
        $output = null;
        if ($name=="header" && $this->yellow->system->get("tableFunctions")) {
            $assetLocation = $this->yellow->system->get("coreServerBase").$this->yellow->system->get("coreAssetLocation");
            $output .= "<script type=\"text/javascript\" defer=\"defer\" src=\"{$assetLocation}table.js\"></script>\n";
        }
        return $output;
    }
    
    // Return FAQ data, HTML encoded
    public function getFaqHtml($fileData, $category, $limit, $random, $language, $accordionId) {
        $filtered = [];
        $output = "";
        // $output = "<p>getFaqHtml output</p>";
        $delimiter = $this->getTableDelimiter($fileData);
        
        foreach ($this->yellow->toolbox->getTextLines($fileData) as $line) {
            $data = str_getcsv($line, $delimiter, "\"", "");
            list($question_id, $question, $answer, $row_category, $row_language) = $data;
            if ($row_category === $category && $row_language === $language) {
                $filtered[] = [
                    'question' => $question,
                    'answer' => $answer
                ];
            }            
        }
        if ($random) {
            shuffle($filtered);
        }
        $filtered = array_slice($filtered, 0, $limit);
        function buildFAQSchemaJSONLD($filtered) {
            $faqItems = [];

            foreach ($filtered as $entry) {
                // Basic sanitization and structuring
                $faqItems[] = [
                    "@type" => "Question",
                    "name" => strip_tags($entry['question']),
                    "acceptedAnswer" => [
                        "@type" => "Answer",
                        "text" => strip_tags($entry['answer'])
                    ]
                ];
            }

            $schema = [
                "@context" => "https://schema.org",
                "@type" => "FAQPage",
                "mainEntity" => $faqItems
            ];

            // Return as JSON-LD (unescaped slashes, pretty print optional)
            return '<script type="application/ld+json">' . json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
        }
        foreach ($filtered as $i => $entry) {
            $isOpen = $i < 3; // Open first 3 items
            $showClass = $isOpen ? ' show' : '';
            $ariaExpanded = $isOpen ? 'true' : 'false';
            $collapsedClass = $isOpen ? '' : ' collapsed';
            $output .= '<div class="card">
                <div class="card-header" id="heading-' . $i . '" data-toggle="collapse" href="#collapse-' . $i . '">
                    <h2 class="mb-0">
                        <a class="btn btn-link' . $collapsedClass . '" data-toggle="collapse" href="#collapse-' . $i . '" role="button"
                            aria-expanded="' . $ariaExpanded . '" aria-controls="collapse-' . $i . '">'
                            . htmlspecialchars($entry['question']) .
                        '</a>
                    </h2>
                </div>
                <div id="collapse-' . $i . '" class="collapse' . $showClass . '" aria-labelledby="heading-' . $i . '">
                    <div class="card-body">'
                        . $entry['answer'] .
                    '</div>
                </div>
            </div>';
        }
        $faqSchema = buildFAQSchemaJSONLD($filtered)."\n";
        return $faqSchema.$this->yellow->lookup->normaliseData($output, "html");
    }
    
    // Return table data, HTML encoded
    public function getTableHtml($fileData, $rowsPerPage, $caption, $class) {
        $output = "";
        $class = trim("csv-table $class");
        $tableFunctions = $this->yellow->system->get("tableFunctions") ? "true" : "false";
        if (is_string_empty($rowsPerPage)) $rowsPerPage = $this->yellow->system->get("tableRowsPerPage");
        if (is_string_empty($caption)) $caption = $this->yellow->page->get("tableCaption");
        $output .= "<table class=\"".htmlspecialchars($class)."\" data-tableFunctions=\"".htmlspecialchars($tableFunctions)."\" data-rowsPerPage=\"".htmlspecialchars($rowsPerPage)."\">\n";
        if (!is_string_empty($caption)) $output .= "<caption>".htmlspecialchars($caption)."</caption>\n";
        $row = $this->yellow->system->get("tableFirstRowHeader") ? 0 : 1;
        $delimiter = $this->getTableDelimiter($fileData);
        foreach ($this->yellow->toolbox->getTextLines($fileData) as $line) {
            $data = str_getcsv($line, $delimiter, "\"", "");
            if ($row==0) {
                $output .= "<thead><tr>\n";
            } else {
                $output .= "<tr>\n";
            }
            for ($column=0; $column<count($data); ++$column) {
                $value = trim($data[$column]);
                if ($row==0) {
                    $output .= "<th>".$value."</th>\n";
                } else {
                    $output .= "<td>".$value."</td>\n";
                }
            }
            if ($row==0) {
                $output .= "</tr></thead>\n";
            } else {
                $output .= "</tr>\n";
            }
            ++$row;
        }
        $output .= "</table>\n";
        return $this->yellow->lookup->normaliseData($output, "html");
    }
    
    // Return table delimiter
    public function getTableDelimiter($fileData) {
        $delimiter = $this->yellow->system->get("tableDelimiter");
        if ($delimiter=="auto") {
            $line = substru($fileData, 0, strposu($fileData, "\n"));
            $delimiterData = array(","=>0, ";"=>0, "|"=>0, "\t"=>0);
            foreach ($delimiterData as $key=>$value) {
                $delimiterData[$key] = substr_count($line, $key);
            }
            arsort($delimiterData);
            $delimiter = array_keys($delimiterData)[0];
        } else {
            $delimiter = str_replace("\\t", "\t", $delimiter);
        }
        return $delimiter;
    }
}
