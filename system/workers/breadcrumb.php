<?php
// Breadcrumb extension, https://github.com/annaesvensson/yellow-breadcrumb

class YellowBreadcrumb {
    const VERSION = "0.9.1";
    public $yellow;         // access to API
    
    // Handle initialisation
    public function onLoad($yellow) {
        $this->yellow = $yellow;
        $this->yellow->system->setDefault("breadcrumbSeparator", ">");
        $this->yellow->system->setDefault("breadcrumbPagesMin", "2");
    }
    
    // Handle page content element
    public function onParseContentElement($page, $name, $text, $attributes, $type) {
        $output = null;
        if ($name=="breadcrumb" && ($type=="block" || $type=="inline")) {
            $separator = $this->yellow->system->get("breadcrumbSeparator");
            $pages = $this->yellow->content->path($page->getLocation(true), true);
            if (count($pages)>=$this->yellow->system->get("breadcrumbPagesMin")) {
                $page->setLastModified($pages->getModified());
                $output = "<div class=\"breadcrumb\" role=\"navigation\" aria-label=\"".$this->yellow->language->getTextHtml("breadcrumbNavigation")."\">";
                foreach ($pages as $pageBreadcrumb) {
                    if ($pageBreadcrumb->getLocation()!=$page->getLocation()) {
                        $output .= "<a href=\"".$pageBreadcrumb->getLocation(true)."\">".$pageBreadcrumb->getHtml("titleNavigation")."</a>";
                        $output .= "<span class=\"separator pl-2 pr-2\">".htmlspecialchars($separator)."</span>";
                    } else {
                        $output .= "<span class=\"active\" aria-current=\"page\">".$pageBreadcrumb->getHtml("titleContent")."</span>";
                    }
                }
                $output .= "</div>\n";
            }
        }
        return $output;
    }
    
    // Handle page extra data
    public function onParsePageExtra($page, $name) {
        return $this->onParseContentElement($page, $name, "", "", "block");
    }
}
