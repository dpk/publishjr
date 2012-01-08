<?php

require './publishjr/Twig/Autoloader.php';
require './publishjr/textile.php';
require './publishjr/markdown.php';
require './publishjr/smartypants.php';

class PublishJr {
  public $config;
  public $site_base;
  public $permalink_format;
  
  public $globals = array();
  
  public $twig;
  public $twig_loader;
  public $templates = array();
  
  public $textile_exts = array('txt', 'textile', 'txle', 'txl');
  public $markdown_exts = array('text', 'mkdn', 'markdown', 'md');
  
  public function __construct() {
    $this->config = json_decode(file_get_contents('./config.json'));
    date_default_timezone_set($this->config->time_zone);
    $this->site_base = $this->config->base_url;
    $this->permalink_format = $this->config->permalinks;
    
    Twig_Autoloader::register();
    $this->twig_loader = new Twig_Loader_Filesystem('./design/');
    $this->twig = new Twig_Environment($this->twig_loader, array('autoescape' => false));
  }
  
  public function publish() {
    $this->move_drafts();
    
    $posts = $this->get_posts();
    $frontpage_posts = array_slice($posts, 0, 10);
    $pages = $this->get_pages();
    
    $ym = array();
    $ymlist = array();
    foreach ($posts as $post) {
      $ym[date('Y', $post->time)][date('m', $post->time)][] = $post;
      $ymlist[date('F Y', $post->time)] = $this->site_base.date('Y/m', $post->time).'/';
    }
    $this->globals['archives'] = $ymlist;
    
    $sections = array();
    $ii = 0;
    foreach ($posts as $post) {
      if ($ii != 0) {
        $post->next = $posts[$ii-1];
      }
      if ($ii != count($posts)-1) {
        $post->previous = $posts[$ii+1];
      }
      if (isset($sections[$post->section])) {
        $sections[$post->section][] = $post;
      } else {
        $sections[$post->section] = array($post);
      }
      $this->publish_page($this->generate_permalink($post, true), 'single.html', array('post' => $post));
      $this->write_page($this->generate_permalink($post, true).'.'.$post->ext, $post->fconts);
      $ii = $ii + 1;
    }
    $this->publish_page('index.html', 'index.html', array('posts' => $frontpage_posts, 'index' => true));
    $this->publish_page('feed', 'feed.xml', array('posts' => $frontpage_posts));
    
    foreach ($ymlist as $name => $link) {
      $folder = preg_replace('{^'.preg_quote($this->site_base).'}', '', $link);
      list($year, $month) = explode('/', $folder);
      $month_posts = array_reverse($ym[$year][$month]);
      $this->publish_page($folder.'/index.html', 'archive.html', array('posts' => $month_posts));
      
      $year_posts = array_flatten($ym);
      $this->publish_page($year.'/index.html', 'archive.html', array('posts' => $year_posts));
    }
    
    foreach ($sections as $section => $posts) {
      $this->publish_page($section.'/index.html', 'section.html', array('articles' => $posts, 'section' => $section));
    }
    
    foreach ($pages as $page) {
      $this->publish_page($page->path.'/index.html', 'page.html', array('page' => $page));
    }
    
    foreach ($this->rscandir('./assets/') as $source) {
      $dest = preg_replace('{^\./assets/}', '', $source);
      $this->write_page($dest, file_get_contents($source));
    }
  }
  
  public function get_posts() {
    $extensions = array_merge($this->textile_exts, $this->markdown_exts);
    $extre = '(?:'.implode('|', $extensions).')';
    $all_files = preg_grep('{\d{4}/\d{2}/\d{2}/.+?\.'.$extre.'$}', $this->rscandir('./content/posts/'));
    $all_posts = array();
        
    foreach ($all_files as $file) {
      preg_match('{([^/]+?)/(\d{4})/(\d{2})/(\d{2})/(.+?)\.('.$extre.')$}', $file, $matches);
      list(, $sect, $y, $mon, $d, $slug, $ext) = $matches;
      $mtime = filemtime($file);
      list($h, $min, $s) = array((int)date('H', $mtime), (int)date('i', $mtime), (int)date('s', $mtime));
      $date = mktime($h, $min, $s, $mon, $d, $y);
      $all_posts[] = $this->make_post(file_get_contents($file), $slug, $ext, $date, $sect, $mtime, $file);
    }
    
    usort($all_posts, function($a, $b) {
        if ($a->time == $b->time) {
          return 0;
        }
        return ($a->time < $b->time) ? 1 : -1;
      });
    
    return $all_posts;
  }
  
  public function get_pages() {
    $extensions = array_merge($this->textile_exts, $this->markdown_exts);
    $extre = '(?:'.implode('|', $extensions).')';
    $all_files = preg_grep('{.+?\.'.$extre.'$}', $this->rscandir('./content/pages/'));
    $all_pages = array();
    
    foreach ($all_files as $file) {
      preg_match('{^\./content/pages/(.+)\.('.$extre.')}', $file, $matches);
      list(, $path, $ext) = $matches;
      
      $all_pages[] = $this->make_page(file_get_contents($file), $path, $ext);
    }
    
    return $all_pages;
  }
  
  public function make_post($fconts, $slug, $ext, $date, $sect, $mtime, $file) {
    $metadata = $this->get_metadata($fconts);
    extract($metadata);
    $body = $this->markup($body, $ext);
    
    $post = new Post;
    $post->time = $date;
    $post->body = $body;
    $post->slug = $slug;
    $post->section = $sect;
    $post->permalink = $this->generate_permalink($post);
    $post->updated = $mtime;
    $post->fconts = $fconts;
    $post->file = $file;
    $post->ext = $ext;
    
    $u = parse_url($post->permalink);
    $post->id = 'tag:'.$u['host'].','.date('Y-m-d', $post->time).':'.$u['path'];
    
    foreach ($metadata as $key => $value) {
      if (is_numeric($value)) {
        $post->{$key} = $value;
      } else {
        $post->{$key} = SmartyPants($value);
      }
    }
    
    return $post;
  }
  
  public function make_page($fconts, $path, $ext) {
    $metadata = $this->get_metadata($fconts);
    extract($metadata);
    $body = $this->markup($body, $ext);
    
    $page = new Page;
    $page->path = $path;
    $page->permalink = $this->site_base.$path;
    $page->body = $body;
    foreach ($metadata as $key => $value) {
      $page->{$key} = $value;
    }
    
    return $page;
  }
  
  private function move_drafts() {
    $extensions = array_merge($this->textile_exts, $this->markdown_exts);
    $extre = '(?:'.implode('|', $extensions).')';
    $drafts = preg_grep('{[^/]+\.'.$extre.'$}', $this->rscandir('./content/drafts/'));
    foreach ($drafts as $draft) {
      $fconts = file_get_contents($draft);
      extract($this->get_metadata($fconts));
      if ($metadata['publish']) {
        $time = strtotime($metadata['publish']);
        if ($time <= time() && $metadata['section']) {
          $this->write_page($metadata['section'].date('/Y/m/d/').basename($draft), $fconts, './content/posts/');
          unlink($draft);
        } else {
          $this->preview_draft($draft, $fconts);
        }
      } else {
        $this->preview_draft($draft, $fconts);
      }
    }
  }
  
  private function preview_draft($draft, $fconts) {
    try {
      $extensions = array_merge($this->textile_exts, $this->markdown_exts);
      $extre = '(?:'.implode('|', $extensions).')';
      preg_match('@/([^/]+?)\.('.$extre.')$@', $draft, $m);
      list(, $slug, $ext) = $m;
      extract($this->get_metadata($fconts));
      if (isset($metadata['section'])) {
        $sect = $metadata['section'];
        $time = isset($metadata['publish']) ? strtotime($metadata['publish']) : time();
        $post = $this->make_post($fconts, $slug, $ext, $time, $sect, $time, '');
        $this->publish_page('drafts/'.$slug, 'single.html', array('post' => $post));
      }
    } catch (Exception $e) {}
  }
  
  private function markup($body, $ext) {
    if (in_array($ext, $this->textile_exts)) {
      $txt = new Textile;
      $body = $txt->TextileThis($body);
    } elseif (in_array($ext, $this->markdown_exts)) {
      $body = SmartyPants(Markdown($body, md5($body)));
    } else {
      $body = SmartyPants($body);
    }
    
    return $body;
  }
  
  private function get_metadata($text) {
    $chunks = explode("\n\n", $text);
    $metadata = $chunks[0];
    $body = substr($text, strlen($metadata));
    
    $mds = array();
    
    $lines = explode("\n", $metadata);
    foreach ($lines as $line) {
      $data = explode(':', $line);
      $key = trim($data[0]);
      $value = trim(implode(':', array_slice($data, 1)));
      $key = strtolower(str_replace(' ', '_', $key));
      $mds[$key] = $value;
    }
    
    return array("metadata" => $mds, "body" => $body);
  }
  
  private function generate_permalink($post, $relative=false) {
    $reps = array(':section' => $post->section,
                  ':year'    => date('Y', $post->time),
                  ':month'   => date('m', $post->time),
                  ':day'     => date('d', $post->time),
                  ':slug'    => $post->slug);
    
    $permalink = str_replace(array_keys($reps), array_values($reps), $this->permalink_format);
    
    if ($relative) {
      return $permalink;
    } else {
      return $this->site_base.$permalink;
    }
  }
  
  private function publish_page($file, $template, $variables) {
    if (!isset($this->templates[$template])) {
      $this->templates[$template] = $this->twig->loadTemplate($template);
    }
    
    $conts = $this->templates[$template]->render(array_merge($variables, $this->globals));
    $this->write_page($file, $conts);
  }
  
  private function write_page($file, $page, $base='./site/') {
    $path = $base.$file;
    if (!is_dir(dirname($path))) {
      mkdir(dirname($path), 0755, true);
    }
    file_put_contents($base.$file, $page);
  }
  
  private function rscandir($base='', &$data=array()) {
    $array = array_diff(scandir($base), array('.', '..'));
     
    foreach ($array as $value) {
      if (is_dir($base.$value)) {
        $data[] = $base.$value.'/';
        $data = $this->rscandir($base.$value.'/', $data);
      } elseif (is_file($base.$value)) {
        $data[] = $base.$value;
      }
    }
    return $data;
  }

}

class Post {
  
}

class Page {
  
}

function widont($str = '') {
  return preg_replace( '|([^\s])\s+([^\s]+)\s*$|', '$1&nbsp;$2', $str);
}

function array_flatten($array) {
  if (!is_array($array)) { 
    return FALSE; 
  } 
  $result = array(); 
  foreach ($array as $key => $value) { 
    if (is_array($value)) { 
      $result = array_merge($result, array_flatten($value)); 
    } 
    else { 
      $result[$key] = $value; 
    } 
  } 
  return $result; 
} 

if (!defined('publishjr_include')) {
  $pjr = new PublishJr;
  $pjr->publish();
}
