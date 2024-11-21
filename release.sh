#!/bin/bash

# SPDX-FileCopyrightText: 2024 Matthias Fehring <https://www.huessenbergnetz.de>
#
# SPDX-License-Identifier: LGPL-3.0-or-later

INFOFILE="hbnimages.xml"
PKGNAME=hbnimages

# get current version
VERSION=`grep "<version>" $INFOFILE | sed 's|.*<version>\([0-9\.]*\)</version>.*|\1|'`

TARBALL="${PKGNAME}-${VERSION}.tar.gz"

# remove previous package
if [ -f $TARBALL ]; then
    rm $TARBALL
fi

tar -c -z -f $TARBALL --exclude-vcs \
    src \
    $INFOFILE

