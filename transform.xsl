<?xml version="1.0" encoding="UTF-8"?>
<!--
  * @file transform.xsl
  *
  * Copyright (c) 2013-2018 Simon Fraser University
  * Copyright (c) 2003-2018 John Willinsky
  * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
  *
  * Apply transformations to JATS XML before serving them via OAI-PMH
  -->

<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform">
	<!--
	  - Parameters received from PHP-land
	  -->
	<xsl:param name="datePublished"/><!-- The publication date, formatted ISO8601 -->
	<xsl:param name="datePublishedDay"/><!-- The day part of the publication date -->
	<xsl:param name="datePublishedMonth"/><!-- The month part of the publication date -->
	<xsl:param name="datePublishedYear"/><!-- The year part of the publication date -->
	<xsl:param name="title"/><!-- The title (for the submission's primary locale) -->
	<xsl:param name="abstract"/><!-- The abstract (for the submission's primary locale) in stripped-down HTML -->
	<xsl:param name="copyrightHolder"/><!-- The copyright holder (for the submission's primary locale) -->
	<xsl:param name="copyrightYear"/><!-- The copyright year -->
	<xsl:param name="licenseUrl"/><!-- The license URL -->
	<xsl:param name="language"/><!-- Article language -->
	<xsl:param name="isUnpublishedXml"/><!-- Whether or not this XML document is published (e.g. via Lens Reader); 1 or 0 -->
	<xsl:param name="sectionTitle"/><!-- Section title -->
	<xsl:param name="journalPath"/><!-- Journal path -->
	<xsl:param name="articleSeq"/><!-- Article sequence in issue -->

	<!--
	  - Identity transform
	  -->
	<!-- This permits almost all content to pass through unmodified. -->
	<xsl:template match="@*|node()">
		<xsl:copy>
			<xsl:apply-templates select="@*|node()"/>
		</xsl:copy>
	</xsl:template>

	<!-- Article language (override supplied value) -->
	<xsl:template match="article/@lang">
		<xsl:attribute name="xml:lang"><xsl:value-of select="$language"/></xsl:attribute>
	</xsl:template>

	<!-- Article language (provide a value when the XML doesn't contain it) -->
	<xsl:template match="article">
		<xsl:copy>
			<xsl:attribute name="xml:lang"><xsl:value-of select="$language"/></xsl:attribute>
			<xsl:apply-templates select="@*|node()"/>
		</xsl:copy>
	</xsl:template>

	<!-- Article sequence (provided to both volume and number elements) -->
	<xsl:template match="volume">
		<xsl:copy>
			<xsl:attribute name="seq"><xsl:value-of select="$articleSeq"/></xsl:attribute>
			<xsl:apply-templates select="@*|node()"/>
		</xsl:copy>
	</xsl:template>
	<xsl:template match="number">
		<xsl:copy>
			<xsl:attribute name="seq"><xsl:value-of select="$articleSeq"/></xsl:attribute>
			<xsl:apply-templates select="@*|node()"/>
		</xsl:copy>
	</xsl:template>

	<!-- Article metadata -->
	<xsl:template match="article-meta">
		<xsl:call-template name="doi-check"/>
		<xsl:call-template name="abstract-check"/>
		<xsl:call-template name="permissions-check"/>
		<xsl:apply-templates/>
	</xsl:template>

	<!--
	  - Permissions
	  -->
	<!-- When no permissions information exists in the document, stamp it from OJS -->
	<xsl:template name="permissions-check">
		<xsl:if test="not(permissions)">
			<xsl:if test="$copyrightYear != '' or $copyrightHolder != '' or $licenseUrl != ''">
				<permissions>
					<xsl:if test="$copyrightYear != ''"><copyright-year><xsl:value-of select="$copyrightYear"/></copyright-year></xsl:if>
					<xsl:if test="$copyrightHolder != ''"><copyright-holder><xsl:value-of select="$copyrightHolder"/></copyright-holder></xsl:if>
					<xsl:if test="$licenseUrl != ''">
						<license>
							<xsl:attribute namespace="xlink" name="href"><xsl:value-of select="$licenseUrl"/></xsl:attribute>
						</license>
					</xsl:if>
				</permissions>
			</xsl:if>
		</xsl:if>
	</xsl:template>

	<!--
	  - DOI
	  -->
	<!-- For when no DOI exists in the document -->
	<xsl:template name="doi-check">
		<xsl:if test="not(article-id[@pub-id-type='doi'])">
			<xsl:if test="$doi != ''">
				<article-id pub-id-type="doi"><xsl:value-of select="$doi"/></article-id>
			</xsl:if>
		</xsl:if>
	</xsl:template>
	<!-- For when a DOI exists, replace it -->
	<xsl:template match="article-id[@pub-id-type='doi']">
		<xsl:choose>
			<xsl:when test="$doi != ''">
				<article-id pub-id-type="doi"><xsl:value-of select="$doi"/></article-id>
			</xsl:when>
			<xsl:otherwise>
				<xsl:apply-templates/>
			</xsl:otherwise>
		</xsl:choose>
	</xsl:template>

	<!--
	  - Publication date
	  -->
	<!-- This element is presumed to exist in the source document. -->
	<xsl:template match="pub-date[@date-type='pub']">
		<pub-date date-type="pub" publication-format="print" iso-8601-date="2002-04-13">
			<xsl:attribute name="iso-8601-date">
				<xsl:value-of select="$datePublished"/>
			</xsl:attribute>
			<day><xsl:value-of select="$datePublishedDay"/></day>
			<month><xsl:value-of select="$datePublishedMonth"/></month>
			<year><xsl:value-of select="$datePublishedYear"/></year>
		</pub-date>
	</xsl:template>

	<!--
	  - Article title
	  -->
	<!-- This element is presumed to exist in the source document. -->
	<xsl:template match="title-group">
		<title-group>
			<article-title><xsl:value-of select="$title"/></article-title>
		</title-group>
	</xsl:template>

	<!--
	  - Article abstract
	  -->
	<!-- Add an abstract element when none exists -->
	<xsl:template name="abstract-check">
		<xsl:if test="not(abstract)">
			<abstract><xsl:value-of select="$abstract" disable-output-escaping="yes"/></abstract>
		</xsl:if>
	</xsl:template>
	<!-- Update an element when one does exist -->
	<xsl:template match="abstract">
		<abstract>
			<xsl:value-of select="$abstract" disable-output-escaping="yes"/>
		</abstract>
	</xsl:template>

	<!--
	  - Journal ID check
	  -->
	<!-- Update an element when one does exist -->
	<xsl:template match="journal-meta/journal-id[@journal-id-type='publisher-id']">
		<journal-id type="publisher-id">
			<xsl:value-of select="$journalPath"/>
		</journal-id>
	</xsl:template>
</xsl:stylesheet>
