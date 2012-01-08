#!/usr/bin/env ruby

require 'rb-inotify'
require 'json'

$config = JSON.parse File.read './config.json'
$update_times = {}

notifier = INotify::Notifier.new

['content', 'assets', 'design'].each do |folder|
  notifier.watch($config['path']+folder, :modify, :move, :delete, :create, :recursive) do |event|
    update event
  end
end

def update event
  path = event.absolute_name
  if $update_times[path] and (Time.now - $update_times[path]) < 2
    return
  end
  
  puts path+"  edited."
  $update_times[path] = Time.now
  `#{$config['path']}update_site`
end

notifier.run
