<?php defined("SYSPATH") or die("No direct script access.");/**
 * Gallery - a web based photo album viewer and editor
 * Copyright (C) 2000-2009 Bharat Mediratta
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or (at
 * your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street - Fifth Floor, Boston, MA  02110-1301, USA.
 */
class Rest_Controller extends Controller {
  public function access_key() {
    $request = json_decode($this->input->post("request"));
    if (empty($request->user) || empty($request->password)) {
      print rest::forbidden("No user or password supplied");
      return;
    }

    $user = identity::lookup_user_by_name($request->user);
    if (empty($user)) {
      print rest::forbidden("User '{$request->user}' not found");
      return;
    }

    if (!identity::is_correct_password($user, $request->password)) {
      print rest::forbidden("Invalid password for '{$request->user}'.");
      return;
    }
    $key = ORM::factory("user_access_token")
      ->where("user_id", $user->id)
      ->find();
    if (!$key->loaded) {
      $key->user_id = $user->id;
      $key->access_key = md5($user->name . rand());
      $key->save();
      Kohana::log("alert",  Kohana::debug($key->as_array()));
    }
    print rest::success(array("token" => $key->access_key));
  }

  public function __call($function, $args) {
    $request = $this->_normalize_request($args);

    if (empty($request->access_token)) {
      print rest::forbidden("No access token supplied.");
      return;
    }

    try {
      if ($this->_set_active_user($request->access_token)) {
        $handler_class = "{$function}_rest";
        $handler_method = "{$request->method}";

        if (!method_exists($handler_class, $handler_method)) {
          print rest::not_implemented("$handler_class::$handler_method is not implemented");
          return;
        }

        print call_user_func(array($handler_class, $handler_method), $request);
      }
    } catch (Exception $e) {
      print rest::internal_error($e);
    }
  }

  private function _normalize_request($args) {
   $method = strtolower($this->input->server("REQUEST_METHOD"));
    if ($method != "get") {
      $request = $this->input->post("request", null);
      if ($request) {
        $request = json_decode($request);
      } else {
        $request = new stdClass();
      }
    } else {
      $request = new stdClass();
      foreach (array_keys($_GET) as $key) {
        if ($key == "request_key") {
          continue;
        }
        $request->$key = $this->input->get($key);
      }
    }

    $override_method = strtolower($this->input->server("HTTP_X_GALLERY_REQUEST_METHOD", null));
    $request->method = empty($override_method) ? $method : $override_method;
    $request->access_token = $this->input->server("HTTP_X_GALLERY_REQUEST_KEY");
    $request->path = implode("/", $args);

    return $request;
  }

  private function _set_active_user($access_token) {
    if (empty($access_token)) {
      $user = identity::guest();
    } else {
      $key = ORM::factory("user_access_token")
        ->where("access_key", $access_token)
        ->find();

      if ($key->loaded) {
        $user = identity::lookup_user($key->user_id);
        if (empty($user)) {
          print rest::forbidden("User not found: {$key->user_id}");
          return false;;
        }
      } else {
        print rest::forbidden("Invalid user access token supplied: {$key->user_id}");
        return false;
      }
    }
    identity::set_active_user($user);
    return true;
  }
}