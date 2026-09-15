# SPDX-FileCopyrightText: 2026 Néfix Estrada
# SPDX-License-Identifier: AGPL-3.0-or-later
{
  description = "FTS SQL — full text search over the database Nextcloud already runs";

  inputs.nixpkgs.url = "github:NixOS/nixpkgs/nixos-unstable";

  outputs = { self, nixpkgs }:
    let
      systems = [
        "x86_64-linux"
        "aarch64-linux"
      ];
      forAllSystems = nixpkgs.lib.genAttrs systems;
    in
    {
      devShells = forAllSystems (system:
        let
          pkgs = nixpkgs.legacyPackages.${system};
          # The manifest declares PHP >= 8.2: develop against the lowest version
          # nixpkgs still ships, so an accidental 8.3-ism fails in this shell and
          # not on a user's host. 8.2 is EOL upstream; when nixpkgs drops it the
          # shell moves to 8.3 and the manifest floor should be reconsidered.
          phpBase = if pkgs ? php82 then pkgs.php82 else pkgs.php83;
          php = phpBase.withExtensions ({ enabled, all }:
            # intl is deliberately removed: DESIGN.md forbids depending on it, and
            # an accidental use has to fail loudly here, where hosts that do ship
            # intl cannot mask it. pdo_sqlite/sqlite3 stay in for FTS5 experiments
            # against a scratch database.
            (nixpkgs.lib.subtractLists [ all.intl ] enabled)
            ++ (with all; [ pdo_sqlite sqlite3 ]));
        in
        {
          default = pkgs.mkShell {
            packages = [
              php
              phpBase.packages.composer
              pkgs.nodejs_22
              pkgs.libxml2 # xmllint, for `make appstore`
              pkgs.gnumake
              pkgs.rsync
              pkgs.gnutar
            ];
          };
        });

      formatter = forAllSystems (system:
        nixpkgs.legacyPackages.${system}.nixfmt-rfc-style);
    };
}
